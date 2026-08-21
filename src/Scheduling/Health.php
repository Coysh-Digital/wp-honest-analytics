<?php
/**
 * Operational health.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Scheduling;

use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Plugin;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Schema\Upgrader;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Paths;
use HonestAnalytics\Write\SpoolStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is wrong, and what is merely worth knowing.
 *
 * The split is the whole point of this class. An earlier version put "there is
 * no persistent object cache" in with the faults, so the majority of WordPress
 * installations — which have no object cache and are perfectly fine without one
 * — got a warning notice on every report screen telling them something was
 * broken when nothing was. A warning that is always on is a warning nobody
 * reads, including the one time it matters.
 *
 * So: `problems()` is for things that mean numbers are being lost right now,
 * and it drives the admin notice. `advisories()` is for things somebody
 * operating the site should know, and it appears on the Settings screen where
 * there is room to explain and something to click.
 */
final class Health {

	/** A drain that has not run in this long, with a backlog waiting, is stuck. */
	private const STALE_DRAIN_SECONDS = 1800;

	private Settings $settings;
	private SpoolStatus $spool;

	/**
	 * @param Settings|null $settings Settings.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? Plugin::instance()->settings();
		$this->spool    = new SpoolStatus( $this->settings );
	}

	/**
	 * Things that are actually going wrong.
	 *
	 * @return string[]
	 */
	public function problems(): array {
		$problems = [];

		if ( ! Upgrader::isCurrent() ) {
			$problems[] = __( 'The database tables are out of date. Open any analytics screen as an administrator to finish the upgrade.', 'honest-analytics' );
		}

		// A missing schedule is not a fault. Counting continues from ordinary
		// page views, and the tidy-up runs from admin requests - the plugin is
		// built to work on sites that have no cron and no way to add one. It is
		// worth mentioning, not worth alarming anybody about, so it lives with
		// the advisories rather than here.

		$stale = $this->staleDrainSeconds();

		if ( null !== $stale && $this->spool->isConcerning() ) {
			$problems[] = sprintf(
				/* translators: 1: human readable duration, 2: description of the backlog. */
				__( 'Nothing has been counted for %1$s and %2$s. Page views normally clear this on their own; a backlog this size on a quiet site usually means the spool cannot be written or the database is refusing writes.', 'honest-analytics' ),
				human_time_diff( time() - $stale ),
				$this->spool->describe()
			);
		}

		$last = $this->lastDrain();

		if ( null !== $last && (int) ( $last['quarantinedBatches'] ?? 0 ) > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of batches. */
				_n(
					'%d batch of hits has been set aside after repeated failures. Use “Retry set-aside batches” below, or run: wp honest-analytics drain --retry',
					'%d batches of hits have been set aside after repeated failures. Use “Retry set-aside batches” below, or run: wp honest-analytics drain --retry',
					(int) $last['quarantinedBatches'],
					'honest-analytics'
				),
				(int) $last['quarantinedBatches']
			);
		}

		if ( $this->usesSpoolFile() && ! $this->spoolIsWritable() ) {
			$problems[] = __( 'The spool directory cannot be written to, so views are being dropped. Switch the write driver to the database on the Settings screen.', 'honest-analytics' );
		}

		if ( $this->spool->isConcerning() ) {
			$problems[] = sprintf(
				/* translators: %s: description of the backlog. */
				__( 'The backlog is building up: %s. The drain is not keeping pace.', 'honest-analytics' ),
				$this->spool->describe()
			);
		}

		if ( $this->settings->enableGeo && ! Plugin::instance()->geo()->isAvailable() ) {
			$problems[] = __( 'Country reporting is switched on but no geo database is installed, so nothing is being recorded against it.', 'honest-analytics' );
		}

		$loopback = ( new Loopback( $this->settings ) )->result();

		if ( Loopback::SPOOL_PUBLIC === $loopback['spool'] ) {
			$problems[] = __( 'The write spool can be read over the web. It holds no addresses, but it is not public data. On nginx this needs a rule in the server config — the exact block is in docs/caching.md. On Apache or IIS, check that the .htaccess or web.config in the spool directory has not been removed.', 'honest-analytics' );
		}

		if ( ! $loopback['endpoint'] && $this->settings->usesBeacon() ) {
			$problems[] = __( 'The collection endpoint did not answer when the site asked itself for it, so nothing sent from a browser is being counted. If a security plugin has disabled the REST API, switch the collect endpoint to the plain URL on this screen.', 'honest-analytics' );
		}

		if ( $this->settings->enableConsent && ! Edition::isPro() ) {
			$problems[] = __( 'Consented tracking is switched on but this site is running the free edition, so the consented layer is inactive.', 'honest-analytics' );
		}

		/**
		 * Filters the list of operational faults.
		 *
		 * @param string[] $problems Fault descriptions.
		 */
		return array_values( (array) apply_filters( 'honest_analytics_health_problems', $problems ) );
	}

	/**
	 * Things worth knowing that are not faults.
	 *
	 * @return string[]
	 */
	public function advisories(): array {
		$advisories = [];

		if ( ! Cron::isScheduled() || Fallback::isCarryingTheLoad() ) {
			$advisories[] = __( 'WordPress is not running scheduled jobs for this site, so counting is being done by ordinary page views instead and the tidy-up runs when somebody opens the admin. That is a supported arrangement and nothing is lost by it. A real cron entry would keep the figures a few minutes fresher on a busy site - the command is below.', 'honest-analytics' );
		}

		if ( ! StoreFactory::usingObjectCache() ) {
			$advisories[] = __( 'There is no persistent object cache on this site, so sessions, nonces and rate limits are kept in a database table. That is a supported arrangement and nothing is lost by it; a Redis or Memcached cache would make the capture path a little cheaper.', 'honest-analytics' );
		} else {
			$advisories[] = __( 'Sessions and nonces are kept in the object cache. If that cache is per-server — APCu, for instance — and this site runs on more than one web server, set HONEST_ANALYTICS_STORE to "db" so every server agrees.', 'honest-analytics' );
		}

		if ( ! Cron::wpCronEnabled() ) {
			$advisories[] = sprintf(
				/* translators: %s: a crontab line. */
				__( 'WP-Cron is switched off, which is the right thing to do on a busy site. Make sure a real cron job runs the drain: %s', 'honest-analytics' ),
				Cron::crontabLine()
			);
		}

		if ( ! $this->settings->autoDrain ) {
			$advisories[] = __( 'The automatic drain is off, so counting depends entirely on cron running. Leave it on unless cron is known to be reliable here.', 'honest-analytics' );
		}

		if ( Settings::TRACKING_SERVER === $this->settings->trackingMode ) {
			$advisories[] = __( 'Tracking is server-side only. Pages served from a full-page cache never reach PHP, so they will not be counted. Hybrid is the safe setting behind a cache.', 'honest-analytics' );
		}

		if ( ! $this->settings->injectScript && $this->settings->usesBeacon() ) {
			$advisories[] = __( 'The tracking mode expects a beacon but script injection is switched off, so nothing is reporting from the browser.', 'honest-analytics' );
		}

		/**
		 * Filters the list of informational advisories.
		 *
		 * @param string[] $advisories Advisory text.
		 */
		return array_values( (array) apply_filters( 'honest_analytics_health_advisories', $advisories ) );
	}

	/**
	 * Whether anything is actually wrong.
	 */
	public function isHealthy(): bool {
		return [] === $this->problems();
	}

	/**
	 * The last drain, if there has been one.
	 *
	 * @return array<string,mixed>|null
	 */
	public function lastDrain(): ?array {
		$value = get_option( 'honest_analytics_last_drain' );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * The last garbage collection, if there has been one.
	 *
	 * @return array<string,mixed>|null
	 */
	public function lastGc(): ?array {
		$value = get_option( 'honest_analytics_last_gc' );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * The session store in use.
	 */
	public function sessionStoreName(): string {
		return Plugin::instance()->sessions()->name();
	}

	/**
	 * The backlog.
	 */
	public function spool(): SpoolStatus {
		return $this->spool;
	}

	/**
	 * How long the drain has been stuck, or null if it has not.
	 *
	 * A drain that has not run for an hour on a site with nothing waiting is
	 * not a fault — it is a quiet site. It only matters when there is something
	 * to count and nothing is counting it.
	 */
	public function staleDrainSeconds(): ?int {
		if ( ! $this->spool->hasBacklog() ) {
			return null;
		}

		$last = $this->lastDrain();
		$at   = null !== $last ? (int) ( $last['at'] ?? 0 ) : 0;

		if ( $at <= 0 ) {
			// Never drained, and there is a backlog. That is the interesting
			// case, and it is the one a fresh install with broken cron hits.
			return self::STALE_DRAIN_SECONDS;
		}

		$elapsed = time() - $at;

		return $elapsed > self::STALE_DRAIN_SECONDS ? $elapsed : null;
	}

	/**
	 * When the visitor salt is next destroyed, in words.
	 *
	 * Deliberately does not ask SaltService, because asking it would mint a
	 * salt: the salt is created by the first pageview, and a diagnostic command
	 * that quietly creates one would make "no salt exists yet" unobservable.
	 */
	public static function describeSaltRotation(): string {
		global $wpdb;

		$table = Tables::name( Tables::SALTS );

		$previous = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$value = $wpdb->get_var( "SELECT nextRotation FROM `$table` WHERE id = 1" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $previous );

		if ( null === $value ) {
			return __( 'Not created yet — it is minted on the first pageview.', 'honest-analytics' );
		}

		$next = (int) strtotime( (string) $value . ' UTC' );

		if ( $next <= 0 ) {
			return __( 'Unknown — the stored rotation time could not be read.', 'honest-analytics' );
		}

		if ( $next <= time() ) {
			return __( 'Due now — it is destroyed on the next pageview or scheduled run.', 'honest-analytics' );
		}

		return sprintf(
			/* translators: 1: formatted date and time, 2: human readable duration. */
			__( '%1$s (in %2$s)', 'honest-analytics' ),
			wp_date( 'j M Y H:i', $next ),
			human_time_diff( time(), $next )
		);
	}

	/**
	 * When the drain last ran, in words.
	 */
	public function lastDrainDescription(): string {
		$last = $this->lastDrain();
		$at   = isset( $last['at'] ) ? (int) $last['at'] : 0;

		if ( $at <= 0 ) {
			return __( 'never', 'honest-analytics' );
		}

		if ( $at > time() ) {
			return __( 'just now', 'honest-analytics' );
		}

		return sprintf(
			/* translators: %s: a human readable duration, such as "5 mins". */
			__( '%s ago', 'honest-analytics' ),
			human_time_diff( $at, time() )
		);
	}

	/**
	 * When a scheduled event next runs, in words.
	 *
	 * @param string $hook One of the Cron hook constants.
	 */
	public function nextRunDescription( string $hook ): string {
		$next = wp_next_scheduled( $hook );

		if ( false === $next ) {
			return __( 'not scheduled', 'honest-analytics' );
		}

		if ( $next <= time() ) {
			return __( 'due now', 'honest-analytics' );
		}

		return sprintf(
			/* translators: %s: a human readable duration, such as "3 mins". */
			__( 'in %s', 'honest-analytics' ),
			human_time_diff( time(), $next )
		);
	}

	/**
	 * Everything at once, for the CLI and the diagnostics panel.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		$plugin = Plugin::instance();

		return [
			'edition'       => Edition::name(),
			'schemaCurrent' => Upgrader::isCurrent(),
			'writeDriver'   => $plugin->writer()->name(),
			'sessionStore'  => $this->sessionStoreName(),
			'objectCache'   => StoreFactory::usingObjectCache(),
			'loopback'      => ( new Loopback( $this->settings ) )->result(),
			'uniques'       => $plugin->uniques()->name(),
			'wpCron'        => Cron::wpCronEnabled(),
			'nextDrain'     => Cron::nextRun( Cron::DRAIN_HOOK ),
			'nextGc'        => Cron::nextRun( Cron::GC_HOOK ),
			'nextSalt'      => Cron::nextRun( Cron::SALT_HOOK ),
			'backlog'       => $this->spool->describe(),
			'lastDrain'     => $this->lastDrain(),
			'lastGc'        => $this->lastGc(),
			'problems'      => $this->problems(),
			'advisories'    => $this->advisories(),
		];
	}

	/**
	 * Whether hits are being written to a file at all.
	 */
	private function usesSpoolFile(): bool {
		return in_array( $this->settings->writeDriver, [ Settings::WRITE_AUTO, Settings::WRITE_SPOOL ], true )
			&& 'spool' === Plugin::instance()->writer()->name();
	}

	/**
	 * Whether the spool directory can be written to.
	 */
	private function spoolIsWritable(): bool {
		$dir = Paths::spoolDir();

		return is_dir( $dir ) && wp_is_writable( $dir );
	}
}
