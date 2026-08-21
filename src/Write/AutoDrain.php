<?php
/**
 * Draining from ordinary traffic.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counting without cron.
 *
 * Plenty of sites have no system cron and an unreliable WP-Cron, and analytics
 * that quietly stop counting on those sites would be worse than useless. So an
 * ordinary request runs the drain instead: at most once a minute, after the
 * visitor already has their page, and for a strictly limited number of seconds.
 *
 * It does not give up when the backlog is large. An earlier version did, on the
 * reasoning that a real backlog wants a real cron - which is true, and no help
 * at all to somebody who cannot have one. A site with no cron would have
 * stopped counting at exactly the moment it most needed to carry on. Instead
 * every run takes a bite of a fixed size, and the backlog comes down over the
 * next few page views rather than over the next few seconds.
 *
 * Worth leaving on even where cron works: it only ever does anything in the gap
 * between one scheduled run and the next.
 */
final class AutoDrain {

	private const THROTTLE_KEY     = 'auto-drain';
	private const THROTTLE_SECONDS = 60;

	/**
	 * How long one inline drain may take.
	 *
	 * Long enough to make real progress on a backlog, short enough that nobody
	 * notices. The visitor already has their page by the time this runs, so the
	 * cost is a PHP worker rather than a wait - but a worker held for a minute
	 * on a small host is still somebody else's slow page.
	 */
	private const BUDGET_SECONDS = 5.0;

	/**
	 * Past this, the backlog is reported as well as drained.
	 *
	 * Not a reason to stop: a reason to say so, because a site steadily falling
	 * behind on page views alone will do better with a cron entry and ought to
	 * be told.
	 */
	private const CONCERNING_BYTES = 2097152;

	private Settings $settings;
	private KeyValueStoreInterface $store;

	/**
	 * @param Settings                    $settings Settings.
	 * @param KeyValueStoreInterface|null $store    Store.
	 */
	public function __construct( Settings $settings, ?KeyValueStoreInterface $store = null ) {
		$this->settings = $settings;
		$this->store    = $store ?? StoreFactory::keyValue();
	}

	/**
	 * Run a drain if it is time and there is something to do.
	 *
	 * @param callable $drainer Returns a Drainer. Deferred so the services are
	 *                          only built when a drain is actually going to run.
	 */
	public function run( callable $drainer ): bool {
		if ( ! $this->settings->autoDrain ) {
			return false;
		}

		if ( Settings::WRITE_DIRECT === $this->settings->writeDriver ) {
			return false;
		}

		// `add()` rather than `set()`, so a store outage fails open and drains
		// every request rather than never draining at all.
		if ( ! $this->store->add( self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS ) ) {
			return false;
		}

		$status = new SpoolStatus( $this->settings );

		if ( ! $status->hasBacklog() ) {
			return false;
		}

		if ( $status->backlogBytes() > self::CONCERNING_BYTES ) {
			Log::warning(
				'The spool is building up faster than page views are draining it. Everything is still being counted, ' .
				'a few seconds at a time - but a cron entry running "wp honest-analytics drain" would clear it sooner.'
			);
		}

		try {
			$instance = $drainer();

			if ( $instance instanceof Drainer ) {
				$instance->run( null, self::BUDGET_SECONDS );

				return true;
			}
		} catch ( \Throwable $e ) {
			Log::warning( 'Automatic drain failed: ' . $e->getMessage() );
		}

		return false;
	}
}
