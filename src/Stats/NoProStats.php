<?php
/**
 * The paid reports' queries, in a build that has no paid reports.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing to report, said without touching the database.
 *
 * Empty rather than absent, and the distinction is the point: the screens that
 * would draw these are not in this build either, so nothing renders an empty
 * table. What this prevents is Plugin naming a class that is not there, on
 * every request, for the sake of a call no free build makes.
 */
final class NoProStats implements ProStatsInterface {

	/**
	 * Sessions and conversions by campaign.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function campaigns( int $siteId, DateRange $range, int $limit = 200 ): array {
		return [];
	}

	/**
	 * Sessions by country.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function countries( int $siteId, DateRange $range, int $limit = 250 ): array {
		return [];
	}

	/**
	 * Sessions by region within a country.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function regions( int $siteId, DateRange $range, int $limit = 100 ): array {
		return [];
	}

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
	public function events( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array {
		return [];
	}

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
	public function outbound( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array {
		return [];
	}

	/**
	 * What people typed into the site's own search.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searches( int $siteId, DateRange $range, int $limit = 200 ): array {
		return [];
	}

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
	public function scrollDepth( int $siteId, DateRange $range, int $limit = 100, ?int $pathDimId = null ): array {
		return [];
	}

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
	public function searchConsoleQueries( int $siteId, DateRange $range, int $limit, int $pathDimId ): array {
		return [];
	}

	/**
	 * Search Console queries across the site.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleTopQueries( int $siteId, DateRange $range, int $limit = 200 ): array {
		return [];
	}

	/**
	 * The pages Search Console reports impressions for.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleTopPages( int $siteId, DateRange $range, int $limit = 200 ): array {
		return [];
	}

	/**
	 * The last date Search Console data covers, or null when none has been imported.
	 *
	 * @param int $siteId Site ID.
	 */
	public function searchConsoleDataThrough( int $siteId ): ?string {
		return null;
	}
}
