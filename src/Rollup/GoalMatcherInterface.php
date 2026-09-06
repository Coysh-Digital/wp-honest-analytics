<?php
/**
 * Goal matching contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Sessions\SessionDelta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turning a batch of hits into conversions, where there are goals to match.
 *
 * The seam between the write path and goals. HitApplier and Drainer each hold
 * one of these and call it on every batch they apply, so it has to be
 * satisfiable by construction in a build that has no goals - both of them
 * catch Throwable, which means a missing class there would not raise anything,
 * it would stop counting.
 *
 * `conversions()` is deliberately not on this contract even though GoalMatcher
 * has it. It returns Goal objects, and a Goal does not survive the strip; its
 * only caller is ProRollupWriter, which strips alongside it.
 */
interface GoalMatcherInterface {

	/**
	 * Match live goals across a batch of hits.
	 *
	 * @param Hit[]                      $hits   Hits in the batch.
	 * @param array<string,SessionDelta> $deltas Deltas, keyed by site and session.
	 */
	public function matchBatch( array $hits, array $deltas ): void;
}
