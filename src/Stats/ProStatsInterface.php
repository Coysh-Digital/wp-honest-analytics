<?php
/**
 * The queries only the paid reports run.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns, locations, events, outbound clicks, searches and scroll depth.
 *
 * A seam that exists for one reason: DashboardScreen and PagesScreen ship in
 * every build and ask the container for this, behind an edition ternary. The
 * ternary decides whether the query runs; it cannot decide whether the class is
 * there to name, and Plugin is loaded on every request.
 *
 * Wider than the free build needs - six of these eleven are reached from
 * screens that survive the strip and five only from ones that do not - because
 * the container returns this type either way, and the stripped screens have to
 * typecheck against it too.
 */
interface ProStatsInterface {

	/**
	 * Sessions and conversions by campaign.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function campaigns( int $siteId, DateRange $range, int $limit = 200 ): array;

	/**
	 * Sessions by country.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function countries( int $siteId, DateRange $range, int $limit = 250 ): array;

	/**
	 * Sessions by region within a country.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function regions( int $siteId, DateRange $range, int $limit = 100 ): array;

	/**
	 * Custom events.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function events( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array;

	/**
	 * Clicks that took somebody off the site.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function outbound( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array;

	/**
	 * What people typed into the site's own search.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searches( int $siteId, DateRange $range, int $limit = 200 ): array;

	/**
	 * How far down each page people read.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function scrollDepth( int $siteId, DateRange $range, int $limit = 100, ?int $pathDimId = null ): array;

	/**
	 * Search Console queries for one page.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int       $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleQueries( int $siteId, DateRange $range, int $limit, int $pathDimId ): array;

	/**
	 * Search Console queries across the site.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleTopQueries( int $siteId, DateRange $range, int $limit = 200 ): array;

	/**
	 * The pages Search Console reports impressions for.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleTopPages( int $siteId, DateRange $range, int $limit = 200 ): array;

	/**
	 * The last date Search Console data covers, or null when none has been imported.
	 *
	 * @param int $siteId Site ID.
	 */
	public function searchConsoleDataThrough( int $siteId ): ?string;
}
