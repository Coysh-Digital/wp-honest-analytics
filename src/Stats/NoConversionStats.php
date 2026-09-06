<?php
/**
 * Conversion reporting in a build with no goals.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No goals defined, none convertible, nothing to report.
 *
 * `sessions()` returns zero rather than the real session count, which looks
 * wrong for a moment and is not: it is only ever the denominator of a
 * conversion rate, and there are no conversions in this build to divide.
 * Returning a real figure would mean running a query to compute rates nobody
 * asks for.
 */
final class NoConversionStats implements ConversionStatsInterface {

	/**
	 * @param int       $siteId Site ID, ignored.
	 * @param DateRange $range  Period, ignored.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function goals( int $siteId, DateRange $range ): array {
		return [];
	}

	/**
	 * @param int       $siteId   Site ID, ignored.
	 * @param DateRange $range    Period, ignored.
	 * @param int       $funnelId Funnel id, ignored.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function funnel( int $siteId, DateRange $range, int $funnelId ): array {
		return [];
	}

	/**
	 * @param int       $siteId Site ID, ignored.
	 * @param DateRange $range  Period, ignored.
	 */
	public function sessions( int $siteId, DateRange $range ): int {
		return 0;
	}
}
