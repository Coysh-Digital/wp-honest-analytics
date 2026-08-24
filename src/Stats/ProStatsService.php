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
		return (array) $this->remember(
			'pro:campaigns',
			$siteId,
			$range,
			fn (): array => $this->computeCampaigns( $siteId, $range, $limit ),
			[
				'limit' => $limit,
			]
		);
	}

	/**
	 * Uncached. See campaigns().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeCampaigns( int $siteId, DateRange $range, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::CAMPAIGNS_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.value AS source, m.value AS medium, c.value AS campaign,
					agg.sessions, agg.bounces, agg.conversions, agg.value
				FROM (
					SELECT r.sourceDimId AS sourceId, r.mediumDimId AS mediumId, r.campaignDimId AS campaignId,
						COALESCE(SUM(r.sessions),0) AS sessions,
						COALESCE(SUM(r.bounces),0) AS bounces,
						COALESCE(SUM(r.conversions),0) AS conversions,
						COALESCE(SUM(r.value),0) AS value
					FROM `$table` r
					WHERE r.siteId = %d AND r.date BETWEEN %s AND %s
					GROUP BY r.sourceDimId, r.mediumDimId, r.campaignDimId
					ORDER BY sessions DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` s ON s.id = agg.sourceId
				LEFT JOIN `$dims` m ON m.id = agg.mediumId
				LEFT JOIN `$dims` c ON c.id = agg.campaignId
				ORDER BY agg.sessions DESC",
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
		return (array) $this->remember(
			'pro:countries',
			$siteId,
			$range,
			fn (): array => $this->computeCountries( $siteId, $range, $limit ),
			[
				'limit' => $limit,
			]
		);
	}

	/**
	 * Uncached. See countries().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeCountries( int $siteId, DateRange $range, int $limit = 250 ): array {
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
		return (array) $this->remember(
			'pro:regions',
			$siteId,
			$range,
			fn (): array => $this->computeRegions( $siteId, $range, $limit ),
			[
				'limit' => $limit,
			]
		);
	}

	/**
	 * Uncached. See regions().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeRegions( int $siteId, DateRange $range, int $limit = 100 ): array {
		global $wpdb;

		$table = Tables::name( Tables::GEO_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS region, agg.countryCode, agg.sessions
				FROM (
					SELECT g.regionDimId AS dimId, g.countryCode,
						COALESCE(SUM(g.sessions),0) AS sessions
					FROM `$table` g
					WHERE g.siteId = %d AND g.date BETWEEN %s AND %s
					GROUP BY g.regionDimId, g.countryCode
					ORDER BY sessions DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` d ON d.id = agg.dimId
				ORDER BY agg.sessions DESC",
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
		return (array) $this->remember(
			'pro:events',
			$siteId,
			$range,
			fn (): array => $this->computeEvents( $siteId, $range, $limit, $pathDimId ),
			[
				'limit'     => $limit,
				'pathDimId' => null === $pathDimId ? '' : $pathDimId,
			]
		);
	}

	/**
	 * Uncached. See events().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 * @param ?int      $pathDimId Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeEvents( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array {
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
				"SELECT d.value AS label, agg.hits, agg.sessions, agg.value
				FROM (
					SELECT e.eventNameDimId AS dimId,
						COALESCE(SUM(e.hits),0) AS hits,
						COALESCE(SUM(e.sessions),0) AS sessions,
						COALESCE(SUM(e.sumValue),0) AS value
					FROM `$table` e
					WHERE $where
					GROUP BY e.eventNameDimId
					ORDER BY hits DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` d ON d.id = agg.dimId
				ORDER BY agg.hits DESC",
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
		return (array) $this->remember(
			'pro:outbound',
			$siteId,
			$range,
			fn (): array => $this->computeOutbound( $siteId, $range, $limit, $pathDimId ),
			[
				'limit'     => $limit,
				'pathDimId' => null === $pathDimId ? '' : $pathDimId,
			]
		);
	}

	/**
	 * Uncached. See outbound().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 * @param ?int      $pathDimId Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeOutbound( int $siteId, DateRange $range, int $limit = 200, ?int $pathDimId = null ): array {
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
				"SELECT h.value AS host, u.value AS url, agg.hits
				FROM (
					SELECT o.targetHostDimId AS hostId, o.targetDimId AS urlId,
						COALESCE(SUM(o.hits),0) AS hits
					FROM `$table` o
					WHERE $where
					GROUP BY o.targetHostDimId, o.targetDimId
					ORDER BY hits DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` h ON h.id = agg.hostId
				LEFT JOIN `$dims` u ON u.id = agg.urlId
				ORDER BY agg.hits DESC",
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
		return (array) $this->remember(
			'pro:searches',
			$siteId,
			$range,
			fn (): array => $this->computeSearches( $siteId, $range, $limit ),
			[
				'limit' => $limit,
			]
		);
	}

	/**
	 * Uncached. See searches().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeSearches( int $siteId, DateRange $range, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::SEARCH_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS term, agg.hits, agg.zeroResults
				FROM (
					SELECT s.termDimId AS dimId,
						COALESCE(SUM(s.hits),0) AS hits,
						COALESCE(SUM(s.zeroResults),0) AS zeroResults
					FROM `$table` s
					WHERE s.siteId = %d AND s.date BETWEEN %s AND %s
					GROUP BY s.termDimId
					ORDER BY hits DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` d ON d.id = agg.dimId
				ORDER BY agg.hits DESC",
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
		return (array) $this->remember(
			'pro:scrollDepth',
			$siteId,
			$range,
			fn (): array => $this->computeScrollDepth( $siteId, $range, $limit, $pathDimId ),
			[
				'limit'     => $limit,
				'pathDimId' => null === $pathDimId ? '' : $pathDimId,
			]
		);
	}

	/**
	 * Uncached. See scrollDepth().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 * @param ?int      $pathDimId Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeScrollDepth( int $siteId, DateRange $range, int $limit = 100, ?int $pathDimId = null ): array {
		global $wpdb;

		$table = Tables::name( Tables::SCROLL_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		$where = 's.siteId = %d AND s.date BETWEEN %s AND %s';
		$args  = [ $siteId, $range->from, $range->to ];

		if ( null !== $pathDimId ) {
			$where .= ' AND s.pathDimId = %d';
			$args[] = $pathDimId;
		}

		$limit = max( 1, $limit );

		// Two queries, because there was no LIMIT in the SQL at all: every
		// scrolled path in the range was grouped on `dimensions.value` - a
		// varchar(500) in the temp table's key - fetched whole, and then cut
		// down to the requested handful in PHP. The shortlist ranks by the
		// hundred-per-cent bucket, which is exactly what the sort below does,
		// so the same rows come back in the same order.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$top = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.pathDimId
				FROM `$table` s
				WHERE $where
				GROUP BY s.pathDimId
				ORDER BY SUM(CASE WHEN s.bucket = 100 THEN s.hits ELSE 0 END) DESC
				LIMIT %d",
				array_merge( $args, [ $limit ] )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$top = array_map( 'intval', (array) $top );

		if ( [] === $top ) {
			return [];
		}

		$where .= ' AND s.pathDimId IN (' . implode( ',', array_fill( 0, count( $top ), '%d' ) ) . ')';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS path, s.bucket, COALESCE(SUM(s.hits),0) AS hits
				FROM `$table` s
				INNER JOIN `$dims` d ON d.id = s.pathDimId
				WHERE $where
				GROUP BY s.pathDimId, d.value, s.bucket",
				array_merge( $args, $top )
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

		return array_values( $byPath );
	}

	/**
	 * The search terms that brought people to one page, from Search Console.
	 *
	 * Position is read back as a weighted average - {@see \HonestAnalytics\Import\Gsc\GscRollupWriter}
	 * stores the summable click-weighted... no, impressions-weighted sum rather
	 * than a daily average, precisely so a multi-day range can be divided back
	 * into one honest average here rather than averaging several daily
	 * averages together, which is not the same number.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $limit     Maximum rows.
	 * @param int       $pathDimId The page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleQueries( int $siteId, DateRange $range, int $limit, int $pathDimId ): array {
		return (array) $this->remember(
			'pro:searchConsoleQueries',
			$siteId,
			$range,
			fn (): array => $this->computeSearchConsoleQueries( $siteId, $range, $limit, $pathDimId ),
			[
				'limit'     => $limit,
				'pathDimId' => $pathDimId,
			]
		);
	}

	/**
	 * Uncached. See searchConsoleQueries().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 * @param int       $pathDimId Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeSearchConsoleQueries( int $siteId, DateRange $range, int $limit, int $pathDimId ): array {
		global $wpdb;

		$table = Tables::name( Tables::SEARCHCONSOLE_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS query, agg.clicks, agg.impressions, agg.sumPosition
				FROM (
					SELECT s.queryDimId AS dimId,
						COALESCE(SUM(s.clicks),0) AS clicks,
						COALESCE(SUM(s.impressions),0) AS impressions,
						COALESCE(SUM(s.sumPosition),0) AS sumPosition
					FROM `$table` s
					WHERE s.siteId = %d AND s.date BETWEEN %s AND %s AND s.pathDimId = %d
					GROUP BY s.queryDimId
					ORDER BY clicks DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` d ON d.id = agg.dimId
				ORDER BY agg.clicks DESC",
				$siteId,
				$range->from,
				$range->to,
				$pathDimId,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::withAveragePosition( (array) $rows );
	}

	/**
	 * The search terms that brought people to the site, across every page.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleTopQueries( int $siteId, DateRange $range, int $limit = 200 ): array {
		return (array) $this->remember(
			'pro:searchConsoleTopQueries',
			$siteId,
			$range,
			fn (): array => $this->computeSearchConsoleTopQueries( $siteId, $range, $limit ),
			[
				'limit' => $limit,
			]
		);
	}

	/**
	 * Uncached. See searchConsoleTopQueries().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeSearchConsoleTopQueries( int $siteId, DateRange $range, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::SEARCHCONSOLE_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS query, agg.clicks, agg.impressions, agg.sumPosition
				FROM (
					SELECT s.queryDimId AS dimId,
						COALESCE(SUM(s.clicks),0) AS clicks,
						COALESCE(SUM(s.impressions),0) AS impressions,
						COALESCE(SUM(s.sumPosition),0) AS sumPosition
					FROM `$table` s
					WHERE s.siteId = %d AND s.date BETWEEN %s AND %s
					GROUP BY s.queryDimId
					ORDER BY clicks DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` d ON d.id = agg.dimId
				ORDER BY agg.clicks DESC",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::withAveragePosition( (array) $rows );
	}

	/**
	 * The pages that earned the most Search Console clicks.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function searchConsoleTopPages( int $siteId, DateRange $range, int $limit = 200 ): array {
		return (array) $this->remember(
			'pro:searchConsoleTopPages',
			$siteId,
			$range,
			fn (): array => $this->computeSearchConsoleTopPages( $siteId, $range, $limit ),
			[
				'limit' => $limit,
			]
		);
	}

	/**
	 * Uncached. See searchConsoleTopPages().
	 *
	 * @param int       $siteId Argument.
	 * @param DateRange $range Argument.
	 * @param int       $limit Argument.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computeSearchConsoleTopPages( int $siteId, DateRange $range, int $limit = 200 ): array {
		global $wpdb;

		$table = Tables::name( Tables::SEARCHCONSOLE_ROLLUP );
		$dims  = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.value AS path, agg.clicks, agg.impressions, agg.sumPosition
				FROM (
					SELECT s.pathDimId AS dimId,
						COALESCE(SUM(s.clicks),0) AS clicks,
						COALESCE(SUM(s.impressions),0) AS impressions,
						COALESCE(SUM(s.sumPosition),0) AS sumPosition
					FROM `$table` s
					WHERE s.siteId = %d AND s.date BETWEEN %s AND %s
					GROUP BY s.pathDimId
					ORDER BY clicks DESC
					LIMIT %d
				) agg
				INNER JOIN `$dims` d ON d.id = agg.dimId
				ORDER BY agg.clicks DESC",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::withAveragePosition( (array) $rows );
	}

	/**
	 * The most recent date this site has any Search Console data for.
	 *
	 * The honest "data through" date every Search Console surface shows -
	 * Google itself reports two to three days behind, so this is never today,
	 * and stating it plainly is what stops the gap from reading as a bug.
	 *
	 * @param int $siteId Site ID.
	 */
	public function searchConsoleDataThrough( int $siteId ): ?string {
		global $wpdb;

		$table = Tables::name( Tables::SEARCHCONSOLE_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$date = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(date) FROM `$table` WHERE siteId = %d", $siteId ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $date ? null : (string) $date;
	}

	/**
	 * Turn a summed `sumPosition` back into an average, and drop the running
	 * total once it has done its job.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with clicks, impressions and sumPosition.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function withAveragePosition( array $rows ): array {
		$out = [];

		foreach ( $rows as $row ) {
			$impressions = (int) $row['impressions'];
			$sumPosition = (float) $row['sumPosition'];

			$out[] = [
				'query'       => (string) ( $row['query'] ?? '' ),
				'path'        => (string) ( $row['path'] ?? '' ),
				'clicks'      => (int) $row['clicks'],
				'impressions' => $impressions,
				'position'    => $impressions > 0 ? $sumPosition / $impressions : 0.0,
			];
		}

		return $out;
	}

	/**
	 * Hold a finished period's answer.
	 *
	 * `ReportCache` decides whether a period is finished; a range containing
	 * today is computed every time, because a reader refreshing to see whether
	 * the morning's post is doing anything deserves the truth.
	 *
	 * Every argument beyond the site and the range goes into `$extra` and
	 * therefore into the key. A limit or a path filter left out of it would
	 * serve the answer to one question as the answer to another - the failure
	 * that looks like data rather than like a bug.
	 *
	 * @param string               $name    Query name.
	 * @param int                  $siteId  Site ID.
	 * @param DateRange            $range   Period.
	 * @param callable             $compute Produces the value.
	 * @param array<string,scalar> $extra   Anything else that changes the answer.
	 */
	private function remember( string $name, int $siteId, DateRange $range, callable $compute, array $extra = [] ): mixed {
		return ReportCache::remember( $name, $siteId, $range, $compute, $extra );
	}
}
