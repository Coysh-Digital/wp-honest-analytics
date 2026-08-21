<?php
/**
 * Rollup writing contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use HonestAnalytics\Sessions\Session;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where aggregated counters end up.
 */
interface RollupSinkInterface {

	/**
	 * Write a batch of aggregates.
	 *
	 * @param PageBucket[]            $buckets        Page buckets.
	 * @param Session[]               $closedSessions Sessions that have finished.
	 * @param InteractionBuckets|null $interactions  Everything that is not a pageview.
	 */
	public function flush( array $buckets, array $closedSessions = [], ?InteractionBuckets $interactions = null ): void;
}
