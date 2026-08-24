<?php
/**
 * The scheduled jobs, run without a schedule.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Scheduling;

use HonestAnalytics\Alerts\AlertChecker;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Gc\GcService;
use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Import\ImportRepository;
use HonestAnalytics\Import\ImportRunner;
use HonestAnalytics\Plugin;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything cron would have done, done by ordinary requests instead.
 *
 * A great many WordPress sites have no system cron, and WP-Cron only fires when
 * somebody loads a page - which on a fully cached site is almost never. A
 * plugin that needs a crontab entry to keep working is a plugin that quietly
 * stops working for the people least able to notice.
 *
 * So nothing here depends on a schedule. The drain already runs from ordinary
 * traffic (see Write\AutoDrain) and the identity salt already rotates when it
 * is next read. This covers what was left: the nightly tidy-up, the traffic
 * alert check that rides along with it, and advancing an import whose
 * progress screen has been closed.
 *
 * Two rules make it safe to hang off a page load. Everything is throttled
 * through the key-value store, so a burst of traffic causes one run rather than
 * a hundred; and everything runs after the response has been flushed, or in the
 * admin where a second is nobody's page-load budget.
 */
final class Fallback {

	/** Once a day is what the cron entry would have done. */
	private const GC_THROTTLE_KEY = 'fallback-gc';

	/** Long enough that a busy site does not keep trying, short enough to catch up. */
	private const IMPORT_THROTTLE_KEY = 'fallback-import';

	private const IMPORT_THROTTLE_SECONDS = 30;

	/**
	 * How long one inline import batch may take.
	 *
	 * Shorter than the runner's own budget, because this one is riding on
	 * somebody's admin page rather than on a background request.
	 */
	private const IMPORT_BUDGET_SECONDS = 5;

	private KeyValueStoreInterface $store;

	/**
	 * @param KeyValueStoreInterface|null $store Store.
	 */
	public function __construct( ?KeyValueStoreInterface $store = null ) {
		$this->store = $store ?? StoreFactory::keyValue();
	}

	/**
	 * Attach to the places an ordinary request passes through.
	 */
	public static function register(): void {
		// The admin, because it is never page-cached and so is the most
		// reliable clock a site without cron has.
		add_action( 'admin_init', [ self::class, 'run' ], 99 );
	}

	/**
	 * Do whatever is due.
	 *
	 * Real admin page loads only. `admin_init` also fires on `admin-ajax.php`,
	 * and Heartbeat ticks every fifteen seconds while the block editor is open
	 * - so with a thirty-second throttle and a five-second import budget, an
	 * active import stalled roughly every other autosave for up to five
	 * seconds. A background request is not the clock this was reaching for.
	 */
	public static function run(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return;
		}

		$fallback = new self();

		$fallback->repairSchedule();
		$fallback->maybeTidyUp();
		$fallback->maybeAdvanceImport();
	}

	/**
	 * Put back any scheduled event that has gone missing.
	 *
	 * `Cron::schedule()` says in its own docblock that it runs "again whenever
	 * the plugin notices an event has gone missing", and until now nothing did
	 * the noticing: its only caller was `Installer::installSite()`. Health
	 * reported the absence as a line of text and left it there.
	 *
	 * Losing an event is ordinary - a migration plugin that rebuilds
	 * `wp_options`, a staging copy, a security tool that clears the cron array
	 * - and three of the four jobs have a fallback that covers it. The Search
	 * Console daily sync has none: `DailySync::run()` is reachable from that
	 * event and from nothing else, so losing it stops query data arriving
	 * silently and for ever.
	 *
	 * `schedule()` only fills gaps, so calling it when nothing is missing costs
	 * one `wp_next_scheduled()` per hook against an array WordPress has already
	 * autoloaded.
	 */
	public function repairSchedule(): void {
		if ( Cron::isScheduled() ) {
			return;
		}

		Cron::schedule();
	}

	/**
	 * Run the daily tidy-up if cron has not.
	 *
	 * Reads the last-run option rather than trusting the throttle alone: the
	 * throttle is a cache entry and caches are cleared, and running a full
	 * retention sweep every time somebody flushes Redis would be a poor way to
	 * spend their database.
	 */
	public function maybeTidyUp(): void {
		// The throttle first, and the option second. `honest_analytics_last_gc`
		// is written with autoload = false - deliberately, it is a fat array
		// nothing on a front-end request needs - so reading it costs an
		// uncached wp_options query, and reading it before the throttle meant
		// paying that on every single admin request all day to answer a
		// question that is only interesting once. The throttle is a cache
		// entry and costs nothing when the answer is "not yet".
		//
		// `add()` rather than `set()`, so a store outage fails open rather than
		// never letting the tidy-up run at all.
		if ( ! $this->store->add( self::GC_THROTTLE_KEY, 1, HOUR_IN_SECONDS ) ) {
			return;
		}

		$last = get_option( 'honest_analytics_last_gc' );
		$at   = is_array( $last ) ? (int) ( $last['at'] ?? 0 ) : 0;

		if ( $at > 0 && ( time() - $at ) < DAY_IN_SECONDS ) {
			return;
		}

		if ( wp_next_scheduled( Cron::GC_HOOK ) > time() && $at > 0 ) {
			// Cron is scheduled and has run before, so it is doing its job and
			// this is not needed. A site whose cron has never run once falls
			// through to the sweep below.
			return;
		}

		try {
			Plugin::instance()->drainer()->run( null, 10.0 );

			( new GcService( Plugin::instance()->settings() ) )->run();
		} catch ( \Throwable $e ) {
			Log::warning( 'The tidy-up could not finish from a page request: ' . $e->getMessage() );
		}

		// Rides the same daily throttle as the tidy-up above, so a site with no
		// WP-Cron still gets checked once a day from whatever admin page happens
		// to load first.
		if ( Edition::isPro() && class_exists( AlertChecker::class ) ) {
			try {
				AlertChecker::maybeCheck();
			} catch ( \Throwable $e ) {
				Log::warning( 'The traffic alert check could not run from a page request: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Push an unfinished import along.
	 *
	 * The wizard drives an import from the browser while its tab is open, which
	 * is the fastest and most visible way to do it. Close the tab and, without
	 * cron, the import would sit there. This is what finishes it: slowly, from
	 * whatever admin pages somebody happens to open, but it does finish.
	 */
	public function maybeAdvanceImport(): void {
		if ( ! class_exists( ImportRunner::class ) || ! class_exists( ImportRepository::class ) ) {
			return;
		}

		if ( ! $this->store->add( self::IMPORT_THROTTLE_KEY, 1, self::IMPORT_THROTTLE_SECONDS ) ) {
			return;
		}

		try {
			$repository = new ImportRepository();
			$due        = $repository->due( 1 );

			if ( [] === $due ) {
				return;
			}

			$job = $due[0];

			$runner   = new ImportRunner( $repository );
			$deadline = time() + self::IMPORT_BUDGET_SECONDS;

			do {
				$job = $runner->runBatch( $job );
			} while ( $job->isActive() && time() < $deadline && ImportJob::STATUS_WAITING !== $job->status );
		} catch ( \Throwable $e ) {
			Log::warning( 'An import could not be advanced from a page request: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether this site looks like it is relying on page views rather than cron.
	 *
	 * Used by the Settings screen to describe what is actually happening rather
	 * than to warn about it. Running without cron is a supported arrangement,
	 * not a fault.
	 */
	public static function isCarryingTheLoad(): bool {
		if ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) ) {
			return true;
		}

		$last = get_option( 'honest_analytics_last_gc' );
		$at   = is_array( $last ) ? (int) ( $last['at'] ?? 0 ) : 0;

		return 0 === $at || ( time() - $at ) > ( 2 * DAY_IN_SECONDS );
	}
}
