<?php
/**
 * The service registry.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics;

/*
 * Above the use block, where the rest of this tree puts it below.
 *
 * Plugin Check reads only the top of a file when it looks for direct-access
 * protection, and this file's use list is long enough to push the guard past
 * that window. It was the one file in the package the directory's own checker
 * reported as unprotected, while the guard sat there unread forty lines
 * further down. The convention is worth keeping everywhere it fits; this file
 * is the one it does not fit.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use HonestAnalytics\Bots\BotFilter;
use HonestAnalytics\Capture\CaptureService;
use HonestAnalytics\Capture\NonceRegistry;
use HonestAnalytics\Capture\PathNormalizer;
use HonestAnalytics\Capture\ScriptInjector;
use HonestAnalytics\Capture\Trackability;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Channels\ChannelClassifier;
use HonestAnalytics\Consent\ConsentService;
use HonestAnalytics\Devices\DeviceParser;
use HonestAnalytics\Dimensions\DimensionCapper;
use HonestAnalytics\Dimensions\DimensionsService;
use HonestAnalytics\Geo\GeoService;
use HonestAnalytics\Goals\FunnelsService;
use HonestAnalytics\Goals\GoalsService;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Identity\SaltService;
use HonestAnalytics\Rollup\Compactor;
use HonestAnalytics\Rollup\DbRollupSink;
use HonestAnalytics\Rollup\GoalMatcher;
use HonestAnalytics\Rollup\JourneyRecorder;
use HonestAnalytics\Rollup\ProRollupWriter;
use HonestAnalytics\Rollup\RollupSinkInterface;
use HonestAnalytics\Sessions\SessionStoreFactory;
use HonestAnalytics\Sessions\SessionStoreInterface;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Settings\SettingsRepository;
use HonestAnalytics\Stats\ContentStatsService;
use HonestAnalytics\Stats\ConversionStatsService;
use HonestAnalytics\Stats\ProStatsService;
use HonestAnalytics\Stats\RealtimeService;
use HonestAnalytics\Stats\StatsService;
use HonestAnalytics\Support\ClientIp;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Paths;
use HonestAnalytics\Support\Server;
use HonestAnalytics\Uniques\ExactUniqueCounter;
use HonestAnalytics\Uniques\HllUniqueCounter;
use HonestAnalytics\Uniques\UniqueCounterInterface;
use HonestAnalytics\Write\DbQueueWriter;
use HonestAnalytics\Write\DirectWriter;
use HonestAnalytics\Write\Drainer;
use HonestAnalytics\Write\HitApplier;
use HonestAnalytics\Write\SpoolWriter;
use HonestAnalytics\Write\WriterInterface;

/**
 * Every service, built on first use.
 *
 * Lazy because most requests need almost none of it: a front-end pageview
 * touches the capture path and nothing else, and an admin screen touches the
 * reports and nothing else. Constructing the lot on `plugins_loaded` would put
 * work on every request to serve the few that need it.
 */
final class Plugin {

	private static ?self $instance = null;

	/**
	 * Built services, by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $services = [];

	private function __construct() {
	}

	/**
	 * The singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Throw the container away. For tests.
	 */
	public static function reset(): void {
		self::$instance = null;

		SettingsRepository::flush();

		// The edition verdict and the licence state are memoised statics too,
		// and both are per-site questions. Flushing only the settings meant
		// every site after the first in a `--network` CLI run, or after any
		// `switch_to_blog()`, inherited the first site's answer - so a network
		// where one site is licensed and the others are not reported Pro
		// everywhere, or the reverse. `Edition::flush()` has existed all along
		// and was called from the Licence screen and from tests, never here.
		Edition::flush();
	}

	/**
	 * Build a service once.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Factory.
	 */
	private function service( string $key, callable $factory ): mixed {
		if ( ! array_key_exists( $key, $this->services ) ) {
			$this->services[ $key ] = $factory();
		}

		return $this->services[ $key ];
	}

	/**
	 * Replace a service. For tests.
	 *
	 * @param string $key   Service key.
	 * @param mixed  $value Service.
	 */
	public function set( string $key, mixed $value ): void {
		$this->services[ $key ] = $value;
	}

	/**
	 * The effective settings.
	 */
	public function settings(): Settings {
		return SettingsRepository::get();
	}

	/**
	 * The request environment.
	 */
	public function server(): Server {
		return $this->service( 'server', static fn (): Server => new Server() );
	}

	/**
	 * The rotating visitor salt.
	 */
	public function salts(): SaltService {
		return $this->service( 'salts', fn (): SaltService => new SaltService( $this->settings() ) );
	}

	/**
	 * Anonymous identity.
	 */
	public function identity(): IdentityService {
		return $this->service( 'identity', fn (): IdentityService => new IdentityService( $this->salts() ) );
	}

	/**
	 * Client address resolution.
	 */
	public function clientIp(): ClientIp {
		return $this->service( 'clientIp', fn (): ClientIp => new ClientIp( $this->server(), $this->settings() ) );
	}

	/**
	 * Crawler detection.
	 */
	public function bots(): BotFilter {
		return $this->service( 'bots', static fn (): BotFilter => new BotFilter() );
	}

	/**
	 * User agent parsing.
	 */
	public function devices(): DeviceParser {
		return $this->service( 'devices', static fn (): DeviceParser => new DeviceParser() );
	}

	/**
	 * Referrer classification.
	 */
	public function channels(): ChannelClassifier {
		return $this->service( 'channels', static fn (): ChannelClassifier => new ChannelClassifier() );
	}

	/**
	 * Path normalisation.
	 */
	public function paths(): PathNormalizer {
		return $this->service( 'paths', fn (): PathNormalizer => new PathNormalizer( $this->settings() ) );
	}

	/**
	 * Dimension lookup.
	 */
	public function dimensions(): DimensionsService {
		return $this->service( 'dimensions', static fn (): DimensionsService => new DimensionsService() );
	}

	/**
	 * The daily cardinality cap.
	 */
	public function capper(): DimensionCapper {
		return $this->service( 'capper', fn (): DimensionCapper => new DimensionCapper( $this->dimensions(), $this->settings() ) );
	}

	/**
	 * Unique visitor counting.
	 */
	public function uniques(): UniqueCounterInterface {
		return $this->service(
			'uniques',
			fn (): UniqueCounterInterface => Settings::UNIQUES_EXACT === $this->settings()->uniqueCounterDriver
				? new ExactUniqueCounter()
				: new HllUniqueCounter( $this->settings() )
		);
	}

	/**
	 * Geo lookup.
	 */
	public function geo(): GeoService {
		return $this->service( 'geo', fn (): GeoService => new GeoService( $this->settings() ) );
	}

	/**
	 * Consent.
	 */
	public function consent(): ConsentService {
		return $this->service( 'consent', fn (): ConsentService => new ConsentService( $this->settings() ) );
	}

	/**
	 * Goal definitions.
	 */
	public function goals(): GoalsService {
		return $this->service( 'goals', static fn (): GoalsService => new GoalsService() );
	}

	/**
	 * Funnel definitions.
	 */
	public function funnels(): FunnelsService {
		return $this->service( 'funnels', fn (): FunnelsService => new FunnelsService( $this->goals() ) );
	}

	/**
	 * Goal matching.
	 */
	public function goalMatcher(): GoalMatcher {
		return $this->service( 'goalMatcher', fn (): GoalMatcher => new GoalMatcher( $this->goals() ) );
	}

	/**
	 * The consented journeys layer.
	 */
	public function journeys(): JourneyRecorder {
		return $this->service(
			'journeys',
			fn (): JourneyRecorder => new JourneyRecorder( $this->settings(), $this->consent(), $this->dimensions() )
		);
	}

	/**
	 * Pro rollup writes.
	 */
	public function proRollups(): ProRollupWriter {
		return $this->service(
			'proRollups',
			fn (): ProRollupWriter => new ProRollupWriter( $this->settings(), $this->goals(), $this->funnels(), $this->goalMatcher() )
		);
	}

	/**
	 * Where aggregates are written.
	 */
	public function rollupSink(): RollupSinkInterface {
		return $this->service(
			'rollupSink',
			fn (): RollupSinkInterface => new DbRollupSink(
				$this->settings(),
				$this->capper(),
				$this->uniques(),
				$this->channels(),
				$this->proRollups()
			)
		);
	}

	/**
	 * The hot session layer.
	 */
	public function sessions(): SessionStoreInterface {
		return $this->service( 'sessions', fn (): SessionStoreInterface => SessionStoreFactory::make( $this->settings() ) );
	}

	/**
	 * Hourly to daily compaction.
	 */
	public function compactor(): Compactor {
		return $this->service( 'compactor', fn (): Compactor => new Compactor( $this->settings(), $this->uniques() ) );
	}

	/**
	 * Hybrid-mode deduplication.
	 */
	public function nonces(): NonceRegistry {
		return $this->service( 'nonces', fn (): NonceRegistry => new NonceRegistry( $this->settings() ) );
	}

	/**
	 * Tracker injection.
	 */
	public function injector(): ScriptInjector {
		return $this->service( 'injector', fn (): ScriptInjector => new ScriptInjector( $this->settings() ) );
	}

	/**
	 * Whether a request should be counted.
	 */
	public function trackability(): Trackability {
		return $this->service(
			'trackability',
			fn (): Trackability => new Trackability( $this->settings(), $this->bots(), $this->paths() )
		);
	}

	/**
	 * The write driver in use.
	 */
	public function writer(): WriterInterface {
		return $this->service(
			'writer',
			function (): WriterInterface {
				$settings = $this->settings();

				switch ( $settings->writeDriver ) {
					case Settings::WRITE_DIRECT:
						return new DirectWriter( $this->hitApplier() );

					case Settings::WRITE_DB:
						return new DbQueueWriter( $settings );

					case Settings::WRITE_SPOOL:
						return new SpoolWriter( $settings );

					case Settings::WRITE_AUTO:
					default:
						// A file is cheaper than a row, so it is preferred where
						// one can be written. Where it cannot - a read-only
						// filesystem, or several web nodes that would each keep
						// their own - the table is the honest answer rather than
						// dropping hits on the floor.
						$dir = Paths::spoolDir( true );

						return is_dir( $dir ) && wp_is_writable( $dir )
							? new SpoolWriter( $settings )
							: new DbQueueWriter( $settings );
				}
			}
		);
	}

	/**
	 * Immediate hit application, for the direct driver.
	 */
	public function hitApplier(): HitApplier {
		return $this->service(
			'hitApplier',
			fn (): HitApplier => new HitApplier(
				$this->settings(),
				$this->sessions(),
				$this->rollupSink(),
				$this->goalMatcher(),
				$this->journeys()
			)
		);
	}

	/**
	 * The scheduled drain.
	 */
	public function drainer(): Drainer {
		return $this->service(
			'drainer',
			fn (): Drainer => new Drainer(
				$this->settings(),
				$this->sessions(),
				$this->rollupSink(),
				$this->goalMatcher(),
				$this->journeys()
			)
		);
	}

	/**
	 * Server-side capture.
	 */
	public function capture(): CaptureService {
		return $this->service(
			'capture',
			fn (): CaptureService => new CaptureService(
				$this->settings(),
				$this->trackability(),
				$this->paths(),
				$this->identity(),
				$this->nonces(),
				$this->injector(),
				$this->bots(),
				$this->geo(),
				$this->consent(),
				$this->writer(),
				$this->clientIp(),
				$this->devices()
			)
		);
	}

	/**
	 * The report queries.
	 */
	public function stats(): StatsService {
		return $this->service( 'stats', fn (): StatsService => new StatsService( $this->settings(), $this->uniques() ) );
	}

	/**
	 * Pro report queries.
	 */
	public function proStats(): ProStatsService {
		return $this->service( 'proStats', static fn (): ProStatsService => new ProStatsService() );
	}

	/**
	 * Content reports.
	 */
	public function contentStats(): ContentStatsService {
		return $this->service( 'contentStats', static fn (): ContentStatsService => new ContentStatsService() );
	}

	/**
	 * Goal and funnel reports.
	 */
	public function conversionStats(): ConversionStatsService {
		return $this->service(
			'conversionStats',
			fn (): ConversionStatsService => new ConversionStatsService( $this->goals(), $this->funnels() )
		);
	}

	/**
	 * The real-time report.
	 */
	public function realtime(): RealtimeService {
		return $this->service( 'realtime', fn (): RealtimeService => new RealtimeService( $this->sessions() ) );
	}

	/**
	 * Record an event from a theme, a plugin or an integration.
	 *
	 * @param string      $name   Event name.
	 * @param float|null  $value  Optional value.
	 * @param string|null $path   Path the event happened on.
	 * @param int|null    $postId Post it relates to.
	 */
	public function trackEvent( string $name, ?float $value = null, ?string $path = null, ?int $postId = null ): bool {
		try {
			return $this->capture()->trackEvent( $name, $value, $path, $postId );
		} catch ( \Throwable $e ) {
			Log::warning( 'Could not record an event: ' . $e->getMessage() );

			return false;
		}
	}
}
