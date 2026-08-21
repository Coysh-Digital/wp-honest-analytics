<?php
/**
 * Scheduled work.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Scheduling;

use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Email\ReportMailer;
use HonestAnalytics\Gc\GcService;
use HonestAnalytics\Plugin;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three jobs: drain the spool, tidy up, and destroy the salt.
 *
 * WP-Cron is not cron. It runs when somebody visits the site, which means a
 * quiet site drains late and a site behind a full-page cache may not drain at
 * all - so the health check names that, the documentation gives the real crontab
 * line, and the automatic drain exists as a backstop. What WP-Cron does give is
 * something that works on every host without anybody configuring anything, and
 * that is the right default.
 */
final class Cron {

	public const DRAIN_HOOK = 'honest_analytics_drain';
	public const GC_HOOK    = 'honest_analytics_gc';
	public const SALT_HOOK  = 'honest_analytics_rotate_salt';

	public const DRAIN_INTERVAL = 'honest_analytics_five_minutes';
	public const SALT_INTERVAL  = 'hourly';

	/** Five minutes: recent enough to feel live, rare enough to be cheap. */
	public const DRAIN_SECONDS = 300;

	/**
	 * Attach the schedule filter and the job handlers.
	 */
	public static function register(): void {
		self::registerSchedules();

		add_action( self::DRAIN_HOOK, [ self::class, 'runDrain' ] );
		add_action( self::GC_HOOK, [ self::class, 'runDaily' ] );
		add_action( self::SALT_HOOK, [ self::class, 'runSaltRotation' ] );
	}

	/**
	 * Make sure the custom interval exists.
	 *
	 * Separate from `register()` because of the order things happen in during
	 * activation: WordPress includes the plugin file *after* `plugins_loaded`
	 * has already fired, so `Bootstrap::boot()` never runs on that request and
	 * `register()` never adds the filter. `wp_schedule_event()` would then be
	 * handed an interval WordPress has never heard of, refuse it, and return
	 * false - and because nothing checks that return value in the ordinary
	 * path, the plugin would activate looking perfectly healthy with no drain
	 * scheduled at all.
	 */
	private static function registerSchedules(): void {
		if ( has_filter( 'cron_schedules', [ self::class, 'schedules' ] ) ) {
			return;
		}

		add_filter( 'cron_schedules', [ self::class, 'schedules' ] );
	}

	/**
	 * Add the five-minute interval.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 *
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ self::DRAIN_INTERVAL ] = [
			'interval' => self::DRAIN_SECONDS,
			'display'  => __( 'Every five minutes (Honest Analytics)', 'honest-analytics' ),
		];

		return $schedules;
	}

	/**
	 * Schedule everything that is not already scheduled.
	 *
	 * Called on activation, on a new site, and again whenever the plugin
	 * notices an event has gone missing.
	 */
	public static function schedule(): void {
		self::registerSchedules();

		if ( ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			wp_schedule_event( time() + 60, self::DRAIN_INTERVAL, self::DRAIN_HOOK );
		}

		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( self::nightly(), 'daily', self::GC_HOOK );
		}

		if ( ! wp_next_scheduled( self::SALT_HOOK ) ) {
			// Hourly, but the rotation itself only happens when it is due. The
			// capture path rotates on demand as well, so this exists for the
			// quiet site where nobody arrives to trigger it - a salt that is
			// meant to be destroyed at 4am should be destroyed at 4am whether or
			// not anyone visits.
			wp_schedule_event( time() + 300, self::SALT_INTERVAL, self::SALT_HOOK );
		}
	}

	/**
	 * Remove every scheduled job.
	 */
	public static function unschedule(): void {
		foreach ( self::hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Every hook this plugin schedules.
	 *
	 * @return string[]
	 */
	public static function hooks(): array {
		return [ self::DRAIN_HOOK, self::GC_HOOK, self::SALT_HOOK ];
	}

	/**
	 * Run the drain.
	 */
	public static function runDrain(): void {
		try {
			Plugin::instance()->drainer()->run();
		} catch ( \Throwable $e ) {
			Log::error( 'Scheduled drain failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Run the nightly maintenance.
	 *
	 * Compaction and retention first, then the scheduled report - in that
	 * order, so a summary email describes the data as it now stands rather than
	 * as it stood before last night's tidy-up.
	 */
	public static function runDaily(): void {
		try {
			// Anything still in the spool is counted before the retention rules
			// run, so a hit captured just before midnight is not deleted by the
			// compaction of the day it belongs to.
			Plugin::instance()->drainer()->run();

			( new GcService( Plugin::instance()->settings() ) )->run();
		} catch ( \Throwable $e ) {
			Log::error( 'Scheduled maintenance failed: ' . $e->getMessage() );
		}

		// Scheduled summaries are Pro, and the free build is packaged without
		// the mailer at all. Without this guard the nightly job logs a fatal
		// every night on wordpress.org installs - caught, so nothing breaks,
		// and therefore never noticed.
		if ( Edition::isPro() && class_exists( ReportMailer::class ) ) {
			try {
				ReportMailer::maybeSend();
			} catch ( \Throwable $e ) {
				Log::error( 'The scheduled report could not be sent: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Rotate the salt, if it is due.
	 */
	public static function runSaltRotation(): void {
		try {
			$salts = Plugin::instance()->salts();

			if ( $salts->nextRotation() > time() ) {
				return;
			}

			$salts->rotateOnce();
		} catch ( \Throwable $e ) {
			Log::error( 'Scheduled salt rotation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * When a job is next due, if it is scheduled at all.
	 *
	 * @param string $hook Hook name.
	 */
	public static function nextRun( string $hook ): ?int {
		$next = wp_next_scheduled( $hook );

		return false === $next ? null : (int) $next;
	}

	/**
	 * Whether every job is scheduled.
	 */
	public static function isScheduled(): bool {
		foreach ( self::hooks() as $hook ) {
			if ( null === self::nextRun( $hook ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether WordPress will run its own cron on page requests.
	 *
	 * False is not a fault: it is what a site with a real system crontab looks
	 * like, and that is the better arrangement. It only becomes a fault when
	 * nothing has actually run.
	 */
	public static function wpCronEnabled(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) );
	}

	/**
	 * The crontab line for this site.
	 *
	 * Given verbatim on the Settings screen and in the documentation, because
	 * "configure a real cron job" is not an instruction anybody can follow.
	 */
	public static function crontabLine(): string {
		return sprintf( '*/5 * * * * cd %s && wp honest-analytics drain --quiet', ABSPATH );
	}

	/**
	 * Tonight, at the quietest hour, in the site's own timezone.
	 */
	private static function nightly(): int {
		$now    = time();
		$target = Timezone::at( $now )->setTime( 3, 20 )->getTimestamp();

		return $target > $now ? $target : $target + 86400;
	}
}
