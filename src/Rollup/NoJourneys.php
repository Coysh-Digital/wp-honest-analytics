<?php
/**
 * Journey recording in a build with no consented tier.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use HonestAnalytics\Capture\Hit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing durable to record against, so nothing is recorded.
 *
 * A journey is a sequence of pages joined by an identity that outlives the day,
 * which a package with no consented tier has no way to obtain - it is cookieless
 * by default. `isEnabled()` answering false is the true state of that build
 * rather than a switch held down.
 */
final class NoJourneys implements JourneyRecorderInterface {

	public function isEnabled(): bool {
		return false;
	}

	/**
	 * @param Hit[] $hits Hits, ignored.
	 */
	public function record( array $hits ): void {
	}
}
