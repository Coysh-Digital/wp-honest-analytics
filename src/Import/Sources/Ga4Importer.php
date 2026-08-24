<?php
/**
 * Importing historical analytics from Google Analytics 4.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Sources;

use DateTimeImmutable;
use HonestAnalytics\Import\DetectionResult;
use HonestAnalytics\Capture\PathNormalizer;
use HonestAnalytics\Import\Ga4\Client;
use HonestAnalytics\Import\Ga4\Connection;
use HonestAnalytics\Import\Ga4\Ga4Exception;
use HonestAnalytics\Import\Ga4\Reports;
use HonestAnalytics\Import\Ga4\ResponseMapper;
use HonestAnalytics\Import\Ga4\TokenStore;
use HonestAnalytics\Import\ImportBatchResult;
use HonestAnalytics\Import\ImportConfiguration;
use HonestAnalytics\Import\ImporterInterface;
use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Import\ImportLog;
use HonestAnalytics\Import\ImportRunner;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Import\ImportSummary;
use HonestAnalytics\Import\MetricMapping;
use HonestAnalytics\Plugin;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The two messages thrown here are already the plain-English sentences a screen shows, and escaping them would put entities in front of the reader.

/**
 * GA4, read a week at a time.
 *
 * The other two importers read a database on the same server. This one reads
 * somebody else's API across the internet, under a quota, with a token that
 * expires - so the shape is different: small date chunks, a cursor that
 * survives a crash, and a rate limit treated as a normal Tuesday rather than
 * as a failure.
 *
 * What it does not do is try to be GA4. There is no attempt to reconstruct
 * events, or users, or anything that would need Google's definitions to be
 * this plugin's definitions. It brings across the aggregates that map onto
 * what this plugin already keeps, and says plainly which of them mean quite
 * the same thing.
 */
final class Ga4Importer implements ImporterInterface {

	/** A week at a time. Small enough to finish in one batch, big enough not to waste requests. */
	private const DEFAULT_CHUNK_DAYS = 7;

	/** Pages of one report inside one batch. Beyond this the chunk is too wide. */
	private const MAX_PAGES = 3;

	/** GA4 did not exist before this, so there is no point asking about it. */
	private const EARLIEST = '2020-01-01';

	/**
	 * A day, in seconds.
	 *
	 * Spelled out rather than using WordPress's DAY_IN_SECONDS, because the
	 * date arithmetic here is pure and the unit suite runs without WordPress
	 * loaded. A class that needs the constant is a class that cannot be tested
	 * without booting a CMS to add up two dates.
	 */
	private const SECONDS_PER_DAY = 86400;

	private ?Client $client;

	private ?PathNormalizer $paths;

	/**
	 * @param Client|null         $client Injected by the tests; built on demand otherwise.
	 * @param PathNormalizer|null $paths  Likewise, so the mapping can be exercised without the container.
	 */
	public function __construct( ?Client $client = null, ?PathNormalizer $paths = null ) {
		$this->client = $client;
		$this->paths  = $paths;
	}

	public function id(): string {
		return ImportSource::GA4;
	}

	public function name(): string {
		return __( 'Google Analytics', 'honest-analytics' );
	}

	/**
	 * What state the connection is in.
	 *
	 * Reads stored options only - no request to Google - because this runs
	 * every time the import screen is opened and a screen that waits on
	 * somebody else's API to draw itself is a screen people learn to dread.
	 */
	public function detect(): DetectionResult {
		$status = Connection::status();

		if ( ! $status['connected'] ) {
			return new DetectionResult(
				DetectionResult::NONE,
				__( 'Connect your Google account to bring historical trends across from GA4.', 'honest-analytics' ),
				null,
				null,
				0,
				true,
				$status['configured'] ? [] : [ (string) $status['hint'] ]
			);
		}

		if ( '' === (string) $status['property'] ) {
			return new DetectionResult(
				DetectionResult::NONE,
				__( 'Connected to Google. Choose which property to import from.', 'honest-analytics' )
			);
		}

		$range = TokenStore::range();

		return new DetectionResult(
			DetectionResult::AVAILABLE,
			'' !== (string) $status['propertyName']
				? sprintf(
					/* translators: %s: the Google Analytics property name. */
					__( 'Ready to import from %s.', 'honest-analytics' ),
					(string) $status['propertyName']
				)
				: __( 'Ready to import.', 'honest-analytics' ),
			null !== $range ? $range['from'] : null,
			null !== $range ? $range['to'] : null
		);
	}

	/**
	 * What is there, and what it will become.
	 *
	 * Three cheap requests: the first day with data, the last, and the totals
	 * between them. Cached against the property, so opening the preview twice
	 * does not spend quota twice.
	 */
	public function inspect(): ImportSummary {
		$property = TokenStore::property();
		$detail   = TokenStore::propertyDetail();
		$notes    = $this->notes();
		$totals   = [];
		$from     = '';
		$to       = '';

		if ( '' === $property ) {
			return new ImportSummary( '', '', [], true, [], [], $this->mappings(), $notes );
		}

		$cached = TokenStore::range();

		try {
			if ( null === $cached || $cached['checkedAt'] < time() - self::SECONDS_PER_DAY ) {
				[ $from, $to ] = $this->discoverRange( $property );

				if ( '' !== $from && '' !== $to ) {
					TokenStore::rememberRange( $from, $to );
				}
			} else {
				$from = $cached['from'];
				$to   = $cached['to'];
			}

			if ( '' !== $from && '' !== $to ) {
				$totals = $this->discoverTotals( $property, $from, $to );
			}
		} catch ( Ga4Exception $e ) {
			Log::warning( 'Could not inspect the Google Analytics property: ' . $e->getMessage() );

			$notes[] = $e->friendly();
		}

		// A property reporting in a different timezone from the site is the one
		// silent way a four-year import can end up a day out from top to
		// bottom. Said here, on the screen, before anybody commits to it.
		$propertyZone = (string) $detail['timezone'];
		$siteZone     = Timezone::site()->getName();

		if ( '' !== $propertyZone && $propertyZone !== $siteZone ) {
			$notes[] = sprintf(
				/* translators: 1: the Google Analytics property timezone, 2: this site's timezone. */
				__( 'This Google Analytics property reports in %1$s and this site works in %2$s. Each day is imported under the date Google filed it, so a day near the boundary may sit alongside a slightly different set of visits than this plugin would have recorded.', 'honest-analytics' ),
				$propertyZone,
				$siteZone
			);
		}

		if ( '' !== $from && $from < self::retentionCutoff() ) {
			$notes[] = __( 'Some of this history is older than your current retention setting. It will be kept regardless - imported history is exempt from the retention window.', 'honest-analytics' );
		}

		return new ImportSummary(
			$from,
			$to,
			$totals,
			true,
			array_values( Reports::describe() ),
			[
				__( 'Individual events, conversions and audiences', 'honest-analytics' ),
				__( 'Browser and operating system, which GA4 groups differently', 'honest-analytics' ),
				__( 'Anything identifying a person', 'honest-analytics' ),
			],
			$this->mappings(),
			$notes
		);
	}

	/**
	 * @return MetricMapping[]
	 */
	public function mappings(): array {
		return [
			new MetricMapping(
				'screenPageViews',
				__( 'Page views', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Both count a page being looked at, so these should line up.', 'honest-analytics' )
			),
			new MetricMapping(
				'sessions',
				__( 'Sessions', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'GA4 decides when a visit starts and ends by its own rules, which are not the same as this plugin\'s thirty-minute window.', 'honest-analytics' )
			),
			new MetricMapping(
				'activeUsers',
				__( 'Unique visitors', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'A GA4 active user is recognised across days and devices. A visitor here is a daily estimate from a salt destroyed every 24 hours, so the two count different things and the numbers will differ.', 'honest-analytics' )
			),
			new MetricMapping(
				'bounceRate',
				__( 'Bounces', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'GA4 works out bounces from engagement rather than from single-page visits.', 'honest-analytics' )
			),
			new MetricMapping(
				'userEngagementDuration',
				__( 'Time on page', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'GA4 measures engaged time, which pauses when somebody switches tab. This plugin measures the gap between page views.', 'honest-analytics' )
			),
			new MetricMapping(
				'sessionSource / sessionMedium',
				__( 'Traffic sources', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'GA4 reports a source name rather than a referring address, so well-known ones are matched to their site and the rest are imported as a channel with no referrer.', 'honest-analytics' )
			),
			new MetricMapping(
				'countryId',
				__( 'Countries', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Both use the standard two-letter country codes.', 'honest-analytics' )
			),
			new MetricMapping(
				'deviceCategory',
				__( 'Devices', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Desktop, mobile and tablet mean the same thing in both. Anything else is imported as unknown.', 'honest-analytics' )
			),
		];
	}

	/**
	 * @return string[]
	 */
	public function notes(): array {
		return [
			__( 'GA4 uses Google\'s own definitions of an event, a user and a session. We import the historical figures that carry across, but "active users" in GA4 are not the same thing as visitors measured here.', 'honest-analytics' ),
			__( 'You may see a noticeable change in visitor and session numbers around the day you switched. Nothing is wrong - the way traffic is measured has changed.', 'honest-analytics' ),
			__( 'Your Google Analytics account is not modified, and nothing is sent to Google beyond the requests needed to read your reports.', 'honest-analytics' ),
		];
	}

	/**
	 * The oldest date this site's own retention setting would otherwise keep.
	 */
	private static function retentionCutoff(): string {
		return ( new DateTimeImmutable( '@' . time() ) )
			->setTimezone( Timezone::site() )
			->modify( '-' . Plugin::instance()->settings()->rollupRetentionMonths . ' months' )
			->format( 'Y-m-d' );
	}

	/**
	 * The property, if it is one, and an empty string if it is not.
	 *
	 * @param string $property The value offered.
	 */
	private static function validProperty( string $property ): string {
		$property = trim( $property );

		return preg_match( '#^properties/[0-9]+$#', $property ) ? $property : '';
	}

	/**
	 * @param ImportConfiguration $configuration What the user chose.
	 *
	 * @return array<string,mixed>
	 */
	public function start( ImportConfiguration $configuration ): array {
		$detail = TokenStore::propertyDetail();

		// The configuration wins over the store. A job started against one
		// property must keep reading that property even if somebody picks a
		// different one while it is still running - otherwise an import
		// quietly mixes two websites into one history.
		//
		// Validated rather than sanitised, because it becomes part of a URL
		// path. `import/start` accepts options over REST with nothing but
		// sanitize_text_field applied, which leaves `/`, `.`, `?`, `#` and
		// `..` intact, and Ga4\Client builds its request path by
		// concatenation. A user with `honest_manage_analytics` but not
		// `manage_options` - the delegation that capability exists for - could
		// otherwise aim the site's bearer token at an API path the wizard
		// never offers. The host is fixed by DATA_BASE, so this is path
		// manipulation rather than SSRF; it is still not theirs to choose.
		return [
			'property'     => self::validProperty(
				(string) $configuration->option( 'property', TokenStore::property() )
			),
			'propertyName' => (string) $configuration->option( 'propertyName', $detail['displayName'] ),
			'timezone'     => (string) $configuration->option( 'timezone', $detail['timezone'] ),
			'next'         => $configuration->dateFrom,
			'chunkDays'    => self::DEFAULT_CHUNK_DAYS,
		];
	}

	/**
	 * Read one chunk of days and hand them over whole.
	 *
	 * @param ImportJob $job    The job.
	 * @param callable  $writer Receives a DayBucket, returns rows written.
	 */
	public function processBatch( ImportJob $job, callable $writer ): ImportBatchResult {
		$started  = microtime( true );
		$cursor   = $job->cursor;
		$property = (string) ( $cursor['property'] ?? TokenStore::property() );
		$chunk    = max( 1, (int) ( $cursor['chunkDays'] ?? self::DEFAULT_CHUNK_DAYS ) );
		$next     = (string) ( $cursor['next'] ?? $job->configuration->dateFrom );
		$last     = $job->configuration->dateTo;

		if ( '' === $property ) {
			throw new \RuntimeException( __( 'No Google Analytics property has been chosen for this import.', 'honest-analytics' ) );
		}

		if ( $next > $last ) {
			return ImportBatchResult::complete( $cursor );
		}

		$chunkEnd = min( $last, self::addDays( $next, $chunk - 1 ) );
		$mapper   = new ResponseMapper( $this->paths ?? Plugin::instance()->paths() );

		try {
			foreach ( Reports::all() as $report ) {
				$this->readReport( $job->id, $mapper, $property, $report, $next, $chunkEnd );
			}
		} catch ( Ga4Exception $e ) {
			return $this->handle( $e, $cursor, $chunk );
		}

		$imported = 0;

		// Written only now the chunk is whole, because the sink replaces a day
		// rather than adding to it: writing after each report would leave every
		// day holding whichever report ran last.
		foreach ( $mapper->buckets() as $bucket ) {
			if ( $bucket->isEmpty() ) {
				continue;
			}

			$imported += (int) $writer( $bucket );
		}

		$cursor['next']      = self::addDays( $chunkEnd, 1 );
		$cursor['chunkDays'] = self::nextChunk( $chunk, microtime( true ) - $started );

		$days = self::daysBetween( $next, $chunkEnd );

		return new ImportBatchResult(
			processed: $mapper->rowsRead(),
			imported: $imported,
			skipped: $mapper->rowsSkipped(),
			daysDone: $days,
			finished: $cursor['next'] > $last,
			cursor: $cursor
		);
	}

	/**
	 * @param ImportJob $job The job.
	 */
	public function cleanUp( ImportJob $job ): void {
		unset( $job );

		// Nothing to tidy. The connection outlives the import on purpose: the
		// next one should not need signing in again, and disconnecting is a
		// decision somebody makes on the screen rather than a side effect of
		// finishing.
	}

	/**
	 * How wide the next chunk should be, given how long this one took.
	 *
	 * The two importers that read a local database check the clock inside their
	 * own loop and stop when the batch budget is spent. This one cannot: a
	 * chunk is up to five reports of up to three pages each, fifteen requests
	 * across the internet, and the days are only written once the whole chunk
	 * is whole - so stopping half way would throw the work away rather than
	 * bank it. What it can do is not ask for that much again.
	 *
	 * Halving on an overrun is the same move a rate limit already triggers, and
	 * growing back one day at a time means a site that was slow for a minute
	 * does not stay on one-day chunks for the rest of a four-year import.
	 *
	 * @param int   $chunk   The chunk size just used, in days.
	 * @param float $seconds How long the batch took.
	 */
	private static function nextChunk( int $chunk, float $seconds ): int {
		$budget = (float) ImportRunner::batchSeconds();

		if ( $seconds > $budget ) {
			return max( 1, (int) floor( $chunk / 2 ) );
		}

		// Comfortably inside it: creep back towards the default.
		if ( $seconds < $budget / 2 ) {
			return min( self::DEFAULT_CHUNK_DAYS, $chunk + 1 );
		}

		return $chunk;
	}

	/**
	 * Read one report for one chunk, following pagination.
	 *
	 * @param int                                                                                                      $importId The job, so a truncation reaches the user's own log.
	 * @param ResponseMapper                                                                                           $mapper   Accumulator.
	 * @param string                                                                                                   $property Property name.
	 * @param array{key:string,dimensions:string[],metrics:string[],limit:int,orderBys:array<int,array<string,mixed>>} $report Report spec.
	 * @param string                                                                                                   $from     Y-m-d.
	 * @param string                                                                                                   $to       Y-m-d.
	 *
	 * @throws Ga4Exception When Google will not answer.
	 */
	private function readReport( int $importId, ResponseMapper $mapper, string $property, array $report, string $from, string $to ): void {
		$offset = 0;
		$pages  = 0;

		do {
			$response = $this->client()->runReport(
				$property,
				$report['dimensions'],
				$report['metrics'],
				$from,
				$to,
				$report['limit'],
				$offset,
				$report['orderBys']
			);

			$mapper->apply( $report['key'], $response['rows'] );

			$offset += count( $response['rows'] );
			++$pages;

			$more = count( $response['rows'] ) > 0 && $offset < (int) $response['rowCount'];

			if ( $more && $pages >= self::MAX_PAGES ) {
				// The *import's* log, not the debug one. Support\Log is gated on
				// WP_DEBUG and off by default, so a truncated report - a figure
				// somebody will later find puzzling - was recorded where nobody
				// would ever see it. ImportLog is what the import details screen
				// renders.
				ImportLog::write(
					$importId,
					ImportLog::WARNING,
					sprintf(
						/* translators: 1: report name, 2: start date, 3: end date, 4: rows taken, 5: rows available. */
						__( 'The Google Analytics %1$s report for %2$s to %3$s had more rows than one batch reads. %4$s of %5$s were taken.', 'honest-analytics' ),
						$report['key'],
						$from,
						$to,
						number_format_i18n( $offset ),
						number_format_i18n( (int) $response['rowCount'] )
					),
					[
						'report' => $report['key'],
						'taken'  => $offset,
						'total'  => (int) $response['rowCount'],
					]
				);

				return;
			}
		} while ( $more );
	}

	/**
	 * Decide what a failure means for the job.
	 *
	 * @param Ga4Exception        $error  What went wrong.
	 * @param array<string,mixed> $cursor Where we were.
	 * @param int                 $chunk  The chunk size in use.
	 */
	private function handle( Ga4Exception $error, array $cursor, int $chunk ): ImportBatchResult {
		if ( Ga4Exception::RATE_LIMIT === $error->kind ) {
			// Narrow the chunk as well as waiting. A quota that keeps being hit
			// at a week at a time will not be survived by patience alone.
			$cursor['chunkDays'] = max( 1, (int) floor( $chunk / 2 ) );

			return ImportBatchResult::waiting(
				$error->retryAfter > 0 ? $error->retryAfter : 300,
				$error->friendly(),
				$cursor,
				$error->getMessage()
			);
		}

		if ( Ga4Exception::TRANSIENT === $error->kind ) {
			return ImportBatchResult::waiting( 60, $error->friendly(), $cursor, $error->getMessage() );
		}

		Log::error( 'The Google Analytics import stopped: ' . $error->getMessage() );

		// Auth and outright refusals need a person, so the job stops and the
		// runner records the reason. The message is already plain English.
		throw new \RuntimeException( $error->friendly() );
	}

	/**
	 * The first and last day this property has anything for.
	 *
	 * @param string $property Property name.
	 *
	 * @return array{0:string,1:string}
	 *
	 * @throws Ga4Exception When Google will not answer.
	 */
	private function discoverRange( string $property ): array {
		$today = Timezone::today();

		$edge = function ( bool $descending ) use ( $property, $today ): string {
			$response = $this->client()->runReport(
				$property,
				[ 'date' ],
				[ 'sessions' ],
				self::EARLIEST,
				$today,
				1,
				0,
				[
					[
						'dimension' => [ 'dimensionName' => 'date' ],
						'desc'      => $descending,
					],
				]
			);

			return ResponseMapper::date( (string) ( $response['rows'][0]['dimensions'][0] ?? '' ) );
		};

		return [ $edge( false ), $edge( true ) ];
	}

	/**
	 * The headline totals for the preview.
	 *
	 * @param string $property Property name.
	 * @param string $from     Y-m-d.
	 * @param string $to       Y-m-d.
	 *
	 * @return array<int,array{label:string,count:int}>
	 *
	 * @throws Ga4Exception When Google will not answer.
	 */
	private function discoverTotals( string $property, string $from, string $to ): array {
		$response = $this->client()->runReport(
			$property,
			[],
			[ 'screenPageViews', 'sessions', 'activeUsers' ],
			$from,
			$to,
			1
		);

		$metrics = $response['rows'][0]['metrics'] ?? [];

		// Pairs rather than a map keyed by the label: two of these labels
		// translating alike would drop a figure from the summary somebody is
		// reading to decide whether to import.
		return [
			[
				'label' => __( 'page views', 'honest-analytics' ),
				'count' => (int) round( (float) ( $metrics[0] ?? 0 ) ),
			],
			[
				'label' => __( 'sessions', 'honest-analytics' ),
				'count' => (int) round( (float) ( $metrics[1] ?? 0 ) ),
			],
			[
				'label' => __( 'unique visitors', 'honest-analytics' ),
				'count' => (int) round( (float) ( $metrics[2] ?? 0 ) ),
			],
		];
	}

	/**
	 * The client, built on demand.
	 */
	private function client(): Client {
		if ( null === $this->client ) {
			$this->client = new Client( Connection::provider() );
		}

		return $this->client;
	}

	/**
	 * A date, some days later.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days How many days.
	 */
	private static function addDays( string $date, int $days ): string {
		$time = strtotime( $date . ' 00:00:00 UTC' );

		if ( false === $time ) {
			return $date;
		}

		return gmdate( 'Y-m-d', $time + $days * self::SECONDS_PER_DAY );
	}

	/**
	 * How many days a range covers, inclusive.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	private static function daysBetween( string $from, string $to ): int {
		$start = strtotime( $from . ' 00:00:00 UTC' );
		$end   = strtotime( $to . ' 00:00:00 UTC' );

		if ( false === $start || false === $end || $end < $start ) {
			return 0;
		}

		return (int) floor( ( $end - $start ) / self::SECONDS_PER_DAY ) + 1;
	}
}
