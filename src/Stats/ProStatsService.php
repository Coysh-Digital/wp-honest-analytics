<?php
/**
 * Pro report queries.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

use HonestAnalytics\Integrations\Registry;
use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns, locations, events, outbound clicks, searches and scroll depth.
 *
 * Separate from the core queries because a Lite site never calls any of it, and
 * the gate is applied before these run rather than in front of the markup.
 */
final class ProStatsService {

	/**
	 * Sessions and conversions by campaign.
	 *
	 * Figures come back as floats: under linear attribution a session touched by
	 * two campaigns gives each a half, and the halves still sum to the sessions
	 * that actually happened.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function campaigns( int $siteId, DateRange $range, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::CAMPAIGNS_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.value AS source, m.value AS medium, c.value AS campaign,
					COALESCE(SUM(r.sessions),0) AS sessions,
					COALESCE(SUM(r.bounces),0) AS bounces,
					COALESCE(SUM(r.conversions),0) AS conversions,
					COALESCE(SUM(r.value),0) AS value
				FROM `$table` r
				INNER JOIN `$dims` s ON s.id = r.sourceDimId
				LEFT JOIN `$dims` m ON m.id = r.mediumDimId
				LEFT JOIN `$dims` c ON c.id = r.campaignDimId
				WHERE r.siteId = %d AND r.date BETWEEN %s AND %s
				GROUP BY s.value, m.value, c.value
				ORDER BY sessions DESC
				LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			$sessions = (float) $row['sessions'];

			$out[] = [
				'source'         => (string) $row['source'],
				'medium'         => null !== $row['medium'] ? (string) $row['medium'] : '',
				'campaign'       => null !== $row['campaign'] ? (string) $row['campaign'] : '',
				'sessions'       => $sessions,
				'bounces'        => (float) $row['bounces'],
				'bounceRate'     => $sessions > 0 ? (float) $row['bounces'] / $sessions * 100 : 0.0,
				'conversions'    => (float) $row['conversions'],
				'value'          => (float) $row['value'],
				'conversionRate' => $sessions > 0 ? (float) $row['conversions'] / $sessions * 100 : 0.0,
			];
		}

		return $out;
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
		global $wpdb;

		$table = Tables::name( Tables::GEO_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT countryCode, COALESCE(SUM(sessions),0) AS sessions
				FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s
				GROUP BY countryCode ORDER BY sessions DESC LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sessions by region.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function regions( int $siteId, DateRange $range, int $limit = 100 ): array {
		global $wpdb;

		$table = Tables::name( Tables::GEO_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS region, g.countryCode, COALESCE(SUM(g.sessions),0) AS sessions
				FROM `$table` g
				INNER JOIN `$dims` d ON d.id = g.regionDimId
				WHERE g.siteId = %d AND g.date BETWEEN %s AND %s
				GROUP BY d.value, g.countryCode ORDER BY sessions DESC LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		global $wpdb;

		$table = Tables::name( Tables::EVENTS_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		$where = 'e.siteId = %d AND e.date BETWEEN %s AND %s';
		$args  = [ $siteId, $range->from, $range->to ];

		if ( null !== $pathDimId ) {
			$where .= ' AND e.pathDimId = %d';
			$args[] = $pathDimId;
		}

		$args[] = max( 1, $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS label,
					COALESCE(SUM(e.hits),0) AS hits,
					COALESCE(SUM(e.sessions),0) AS sessions,
					COALESCE(SUM(e.sumValue),0) AS value
				FROM `$table` e
				INNER JOIN `$dims` d ON d.id = e.eventNameDimId
				WHERE $where
				GROUP BY d.value ORDER BY hits DESC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			$hits     = (int) $row['hits'];
			$sessions = (int) $row['sessions'];

			$name = (string) $row['label'];

			$out[] = [
				// The stored name is a stable handle - `form:cf7-5` - so that a
				// goal keeps matching after somebody renames the form. Asking
				// the integration what that form is called *now* is what turns
				// it back into something a person can read.
				'label'      => Registry::label( $name ),
				'name'       => $name,
				'hits'       => $hits,
				'sessions'   => $sessions,
				'perSession' => $sessions > 0 ? $hits / $sessions : 0.0,
				'value'      => (float) $row['value'],
			];
		}

		return $out;
	}

	/**
	 * Outbound clicks and downloads.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function outbound( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array {
		global $wpdb;

		$table = Tables::name( Tables::OUTBOUND_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		$where = 'o.siteId = %d AND o.date BETWEEN %s AND %s';
		$args  = [ $siteId, $range->from, $range->to ];

		if ( null !== $pathDimId ) {
			$where .= ' AND o.pathDimId = %d';
			$args[] = $pathDimId;
		}

		$args[] = max( 1, $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.value AS host, u.value AS url, COALESCE(SUM(o.hits),0) AS hits
				FROM `$table` o
				INNER JOIN `$dims` h ON h.id = o.targetHostDimId
				LEFT JOIN `$dims` u ON u.id = o.targetDimId
				WHERE $where
				GROUP BY h.value, u.value ORDER BY hits DESC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Site searches.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searches( int $siteId, DateRange $range, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::SEARCH_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS term, COALESCE(SUM(s.hits),0) AS hits, COALESCE(SUM(s.zeroResults),0) AS zeroResults
				FROM `$table` s
				INNER JOIN `$dims` d ON d.id = s.termDimId
				WHERE s.siteId = %d AND s.date BETWEEN %s AND %s
				GROUP BY d.value ORDER BY hits DESC LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * How far down pages people read.
	 *
	 * Cumulative by nature: everybody who reached 75% also passed 25% and 50%.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function scrollDepth( int $siteId, DateRange $range, int $limit = 100, ?int $pathDimId = null ): array {
		global $wpdb;

		$table = Tables::name( Tables::SCROLL_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		$where = 's.siteId = %d AND s.date BETWEEN %s AND %s';
		$args  = [ $siteId, $range->from, $range->to ];

		if ( null !== $pathDimId ) {
			$where .= ' AND s.pathDimId = %d';
			$args[] = $pathDimId;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS path, s.bucket, COALESCE(SUM(s.hits),0) AS hits
				FROM `$table` s
				INNER JOIN `$dims` d ON d.id = s.pathDimId
				WHERE $where
				GROUP BY d.value, s.bucket",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$byPath = [];

		foreach ( (array) $rows as $row ) {
			$path   = (string) $row['path'];
			$bucket = (int) $row['bucket'];

			if ( ! in_array( $bucket, [ 25, 50, 75, 100 ], true ) ) {
				continue;
			}

			if ( ! isset( $byPath[ $path ] ) ) {
				$byPath[ $path ] = [
					'path'       => $path,
					'reached25'  => 0,
					'reached50'  => 0,
					'reached75'  => 0,
					'reached100' => 0,
				];
			}

			$byPath[ $path ][ 'reached' . $bucket ] = (int) $row['hits'];
		}

		uasort( $byPath, static fn ( array $a, array $b ): int => $b['reached100'] <=> $a['reached100'] );

		return array_slice( array_values( $byPath ), 0, max( 1, $limit ) );
	}
}
