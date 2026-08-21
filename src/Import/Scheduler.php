<?php
/**
 * Keeping an import moving.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Scheduling\Cron;
use HonestAnalytics\Support\Lock;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three clocks, because no single one of them is reliable enough.
 *
 * **A self-chaining event.** Each batch schedules the next for a second's time,
 * so an import moves at whatever pace the site's traffic allows. On a busy site
 * that is fast; on a quiet one it is the next visitor.
 *
 * **A tick from the open tab.** The progress screen calls a REST route every
 * few seconds while somebody is watching. This is the most reliable clock the
 * plugin has: WP-Cron only fires when a request reaches PHP, and on a site
 * behind a full-page cache most requests do not - but the admin is never
 * cached, so a person watching a progress bar *is* a guaranteed heartbeat.
 * It is also the case that matters, because the person who just started an
 * import is usually still looking at it.
 *
 * **The five-minute drain.** The safety net for the tab that was closed. It
 * already runs, it already fires on any request that reaches PHP, and picking
 * up a stalled import costs it one indexed query when there is nothing to do.
 *
 * All three call the same runner, and a database lock makes sure only one of
 * them is ever inside it. An import that ran twice at once would not corrupt
 * anything - the sink replaces days rather than adding to them - but it would
 * waste a shared host's afternoon.
 */
final class Scheduler {

	/** The self-chaining single event. */
	public const HOOK = 'honest_analytics_import_batch';

	/** Only one worker at a time, whichever clock woke it. */
	private const LOCK = 'import-runner';

	/**
	 * Attach the handlers.
	 */
	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'runDue' ] );

		// The safety net. Cheap when there is nothing to do, which is almost
		// always.
		add_action( Cron::DRAIN_HOOK, [ self::class, 'runDue' ], 20 );
	}

	/**
	 * Ask for a batch as soon as WordPress will give us one.
	 *
	 * @param int $delay Seconds to wait first.
	 */
	public static function nudge( int $delay = 1 ): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK );
	}

	/**
	 * Run one batch of whatever is due.
	 *
	 * Deliberately one job per wake-up rather than all of them: two imports
	 * running at once on shared hosting is how a migration becomes an outage,
	 * and the second one loses nothing by waiting a few seconds.
	 */
	public static function runDue(): void {
		// A MySQL advisory lock, so it is released when the connection ends
		// whether or not this process got to the `finally`. A batch killed by a
		// PHP timeout therefore frees the lock rather than blocking the import
		// until somebody notices.
		$lock = new Lock( self::LOCK );

		if ( ! $lock->acquire() ) {
			return;
		}

		try {
			$runner = new ImportRunner();
			$due    = $runner->repository()->due( 1 );

			if ( [] === $due ) {
				return;
			}

			$job = $runner->runBatch( $due[0] );

			if ( $job->isActive() ) {
				self::nudge( $job->retryAfter > time() ? max( 1, $job->retryAfter - time() ) : 1 );
			}
		} catch ( \Throwable $e ) {
			// The runner already records failures against the job it was
			// running. Anything reaching here is the scheduler itself, and
			// swallowing it keeps a broken import from taking the drain with it.
			Log::warning( 'Import scheduler failed: ' . $e->getMessage() );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Whether an import is currently waiting or running for this site.
	 *
	 * @param int $siteId Site.
	 */
	public static function hasActive( int $siteId ): bool {
		foreach ( ( new ImportRepository() )->forSite( $siteId, 10 ) as $job ) {
			if ( $job->isActive() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop the scheduled event. Called on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
