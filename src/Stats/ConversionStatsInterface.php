<?php
/**
 * Conversion reporting contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What converted, and how far through a funnel people got.
 *
 * The seam is here rather than around GoalsService because of what crosses it.
 * These three return rows and a count; GoalsService returns Goal objects, and a
 * Goal does not survive the strip - an interface naming one would only move the
 * problem to whichever file declared it.
 *
 * The container returns this type whatever is in the package, which is why it
 * cannot simply omit it.
 */
interface ConversionStatsInterface {

	/**
	 * Conversions per goal.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function goals( int $siteId, DateRange $range ): array;

	/**
	 * The steps of one funnel.
	 *
	 * @param int       $siteId   Site ID.
	 * @param DateRange $range    Period.
	 * @param int       $funnelId Funnel id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function funnel( int $siteId, DateRange $range, int $funnelId ): array;

	/**
	 * Sessions in a period: the denominator for every conversion rate.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 */
	public function sessions( int $siteId, DateRange $range ): int;
}
