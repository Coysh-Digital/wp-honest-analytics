<?php
/**
 * Goal matching in a build with no goals.
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
 * Nothing to match against, so nothing happens.
 *
 * Not a disabled GoalMatcher: a package without goals has none to match, no
 * screen to define one on and no row that could hold one. This is what the
 * write path is handed instead, so applying a batch is the same call either way.
 */
final class NoGoalMatching implements GoalMatcherInterface {

	/**
	 * @param Hit[]                      $hits   Hits in the batch, ignored.
	 * @param array<string,SessionDelta> $deltas Deltas, ignored.
	 */
	public function matchBatch( array $hits, array $deltas ): void {
	}
}
