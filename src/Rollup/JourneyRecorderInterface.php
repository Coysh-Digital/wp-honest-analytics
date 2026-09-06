<?php
/**
 * Journey recording contract.
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
 * The stored path a consenting visitor took through the site.
 *
 * The seam between the write path and the consented tier. HitApplier and
 * Drainer each hold one and call it on every batch they apply - and both catch
 * Throwable, so a class missing there would not raise anything anybody sees.
 */
interface JourneyRecorderInterface {

	/**
	 * Whether journeys are being recorded at all.
	 */
	public function isEnabled(): bool;

	/**
	 * Record the steps in a batch of hits.
	 *
	 * @param Hit[] $hits Hits.
	 */
	public function record( array $hits ): void;
}
