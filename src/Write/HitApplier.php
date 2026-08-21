<?php
/**
 * Applying a single hit immediately.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Rollup\Aggregator;
use HonestAnalytics\Rollup\GoalMatcher;
use HonestAnalytics\Rollup\JourneyRecorder;
use HonestAnalytics\Rollup\RollupSinkInterface;
use HonestAnalytics\Sessions\SessionDelta;
use HonestAnalytics\Sessions\SessionStoreInterface;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The direct driver's write path.
 *
 * Only for very low traffic and for tests: every pageview becomes a database
 * write, and two views in the same instant can race. The settings screen says
 * as much beside the control.
 *
 * Note that this closes no sessions. Everything session-scoped - sources,
 * devices, entry and exit, bounce, campaigns, goals - is written when a visit
 * goes idle, and only the drain does that. A site on the direct driver still
 * needs the scheduled drain running.
 */
final class HitApplier {

	public function __construct(
		private Settings $settings,
		private SessionStoreInterface $sessions,
		private RollupSinkInterface $sink,
		private GoalMatcher $matcher,
		private JourneyRecorder $journeys
	) {
	}

	/**
	 * Apply one hit.
	 *
	 * @param Hit $hit The hit.
	 */
	public function apply( Hit $hit ): void {
		global $wpdb;

		try {
			$batchId = 'single-' . bin2hex( random_bytes( 8 ) );

			// Read the session first: a hit's own referrer is only correct for
			// the first page of a visit, and the acquisition referrer is what
			// the page-sources report wants.
			$session     = $this->sessions->get( $hit->siteId, $hit->sessionKey );
			$acquisition = null !== $session && '' !== $session->referrer ? $session->referrer : $hit->referrer;

			$aggregator = new Aggregator( $this->settings );
			$aggregator->add( $hit, $acquisition );

			$delta = SessionDelta::fromHit( $hit );

			$this->matcher->matchBatch( [ $hit ], [ SessionDelta::key( $hit->siteId, $hit->sessionKey ) => $delta ] );

			$this->sessions->apply( $delta, $batchId );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'START TRANSACTION' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			try {
				$this->sink->flush( $aggregator->buckets(), [], $aggregator->interactions );
				$this->journeys->record( [ $hit ] );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->query( 'COMMIT' );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} catch ( \Throwable $e ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->query( 'ROLLBACK' );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				throw $e;
			}
		} catch ( \Throwable $e ) {
			Log::warning( 'Direct write failed: ' . $e->getMessage() );
		}
	}
}
