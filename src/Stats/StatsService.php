<?php
/**
 * Report queries.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

use HonestAnalytics\Dimensions\DimensionsService;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Rollup\Compactor;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Uniques\UniqueCounterInterface;
use HonestAnalytics\Uniques\UniqueScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the reports ask the database.
 *
 * Every query here reads pre-aggregated rows, so a year of history costs about
 * what a month does. The one rule that runs through all of it: unique visitors
 * are **merged**, never summed. Adding up daily figures to produce a monthly one
 * counts a regular reader once per visit, and produces a number that is wrong
 * in proportion to how loyal the audience is.
 */
final class StatsService {

	private Settings $settings;
	private UniqueCounterInterface $counter;

	/**
	 * @param Settings               $settings Settings.
	 * @param UniqueCounterInterface $counter  Unique counter.
	 */
	public function __construct( Settings $settings, UniqueCounterInterface $counter ) {
		$this->settings = $settings;
		$this->counter  = $counter;
	}

	// -------------------------------------------------------------- headline

	/**
	 * The headline figures for a period.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<string,int|float>
	 */
	public function totals( int $siteId, DateRange $range ): array {
		return $this->remember(
			'totals',
			$siteId,
			$range,
			function () use ( $siteId, $range ): array {
				global $wpdb;

				$pages    = Tables::name( Tables::PAGES_ROLLUP );
				$sessions = Tables::name( Tables::SESSIONS_ROLLUP );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$pageRow = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(views),0) AS views, COALESCE(SUM(totalDwellMs),0) AS dwell
						FROM `$pages` WHERE siteId = %d AND date BETWEEN %s AND %s",
						$siteId,
						$range->from,
						$range->to
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$sessionRow = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(sessions),0) AS sessions, COALESCE(SUM(bounces),0) AS bounces,
							COALESCE(SUM(totalDurationMs),0) AS duration, COALESCE(SUM(totalPageviews),0) AS pageviews
						FROM `$sessions` WHERE siteId = %d AND date BETWEEN %s AND %s",
						$siteId,
						$range->from,
						$range->to
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$views       = (int) ( $pageRow['views'] ?? 0 );
				$dwell       = (int) ( $pageRow['dwell'] ?? 0 );
				$sessionsNum = (int) ( $sessionRow['sessions'] ?? 0 );
				$bounces     = (int) ( $sessionRow['bounces'] ?? 0 );
				$duration    = (int) ( $sessionRow['duration'] ?? 0 );
				$pageviews   = (int) ( $sessionRow['pageviews'] ?? 0 );

				return [
					'views'              => $views,
					'uniques'            => $this->uniquesFor( $siteId, $range ),
					'sessions'           => $sessionsNum,
					// At site level the denominator is sessions. At page level it
					// is entrances, because a bounce is only ever counted against
					// the page a visit started on.
					'bounceRate'         => $sessionsNum > 0 ? $bounces / $sessionsNum * 100 : 0.0,
					'avgDurationMs'      => $sessionsNum > 0 ? (int) round( $duration / $sessionsNum ) : 0,
					'avgViewsPerSession' => $sessionsNum > 0 ? $pageviews / $sessionsNum : 0.0,
					'avgDwellMs'         => $views > 0 ? (int) round( $dwell / $views ) : 0,
				];
			}
		);
	}

	/**
	 * Distinct visitors across a period.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int|null  $pathDimId Restrict to one page.
	 */
	public function uniquesFor( int $siteId, DateRange $range, ?int $pathDimId = null ): int {
		// Native rows carry a sketch; imported rows carry a plain count, and the
		// two are added rather than merged. A visitor count cannot be turned
		// back into a sketch - you would have to invent the identities it was
		// built from - and a sketch of invented identities is a lie that merges
		// convincingly. Adding is also right rather than merely convenient:
		// uniques here are daily, the salt rotates every night, so two days
		// already sum. An imported day and a native day sum for the same reason.
		return (int) $this->remember(
			'uniques:' . ( $pathDimId ?? '' ),
			$siteId,
			$range,
			function () use ( $siteId, $range, $pathDimId ): int {
				$rows     = $this->uniqueRows( $siteId, $range, $pathDimId );
				$scopes   = [];
				$sketches = [];

				$imported = 0;

				foreach ( $rows as $row ) {
					$scope    = self::scopeFor( $siteId, $row );
					$scopes[] = $scope;

					if ( array_key_exists( 'uniques', $row ) ) {
						$sketches[ $scope->key() ] = $this->readBlob( $row['uniques'] );
					}

					$imported += (int) ( $row['importedUniques'] ?? 0 );
				}

				return $this->counter->estimate( $scopes, $sketches ) + $imported;
			}
		);
	}

	/**
	 * Distinct visitors who started a session.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 */
	public function sessionUniques( int $siteId, DateRange $range ): int {
		global $wpdb;

		$table = Tables::name( Tables::SESSIONS_ROLLUP );

		$columns = $this->counter->storesOnRow() ? 'date, hour, uniques, importedUniques' : 'date, hour, importedUniques';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $columns FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$scopes   = [];
		$sketches = [];

		$imported = 0;

		foreach ( (array) $rows as $row ) {
			$scope    = new UniqueScope( UniqueScope::KIND_SESSION, $siteId, (string) $row['date'], (int) $row['hour'] );
			$scopes[] = $scope;

			if ( array_key_exists( 'uniques', $row ) ) {
				$sketches[ $scope->key() ] = $this->readBlob( $row['uniques'] );
			}

			$imported += (int) ( $row['importedUniques'] ?? 0 );
		}

		return $this->counter->estimate( $scopes, $sketches ) + $imported;
	}

	/**
	 * The scope a uniques row belongs to.
	 *
	 * A row with no `pathDimId` came from `honest_daily_uniques` and covers the
	 * whole site for that day; one with a path came from the per-path rollup.
	 * Both shapes reach the same three readers, and neither has to know which.
	 *
	 * @param int                 $siteId Site ID.
	 * @param array<string,mixed> $row    One uniques row.
	 */
	private static function scopeFor( int $siteId, array $row ): UniqueScope {
		$pathDimId = $row['pathDimId'] ?? null;

		if ( null === $pathDimId ) {
			return new UniqueScope( UniqueScope::KIND_SITE, $siteId, (string) $row['date'], UniqueScope::HOUR_DAILY );
		}

		return new UniqueScope(
			UniqueScope::KIND_PAGE,
			$siteId,
			(string) $row['date'],
			(int) $row['hour'],
			(int) $pathDimId
		);
	}

	/**
	 * The rows a unique count has to union.
	 *
	 * The sketch column is only selected when the counter actually reads it:
	 * on a driver that keeps its data elsewhere, selecting the blob drags a few
	 * kilobytes per row across the wire for nothing, tens of thousands of times.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function uniqueRows( int $siteId, DateRange $range, ?int $pathDimId = null ): array {
		global $wpdb;

		$table   = Tables::name( Tables::PAGES_ROLLUP );
		$columns = $this->counter->storesOnRow() ? 'date, hour, pathDimId, uniques, importedUniques' : 'date, hour, pathDimId, importedUniques';

		if ( null !== $pathDimId ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT $columns FROM `$table` WHERE siteId = %d AND pathDimId = %d AND date BETWEEN %s AND %s",
					$siteId,
					$pathDimId,
					$range->from,
					$range->to
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Site-wide: one pre-merged row per day, from honest_daily_uniques,
		// rather than every (date, hour, path) row in the range. The per-path
		// rows can answer this too - by merging all of them - and that is what
		// this did: around 191,000 rows for a thirty-day range at the default
		// dimension cap, each carrying a sketch, deserialised and merged in
		// PHP, twice per render and four times with a comparison period. The
		// day rows are written by the drain as the hits arrive; ADR 8 has the
		// reasoning.
		$daily = Tables::name( Tables::DAILY_UNIQUES );

		$columns = $this->counter->storesOnRow()
			? 'date, uniques, importedUniques'
			: 'date, importedUniques';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $columns FROM `$daily` WHERE siteId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Shaped like the per-path rows so the three readers above do not have
		// to know which of the two they are looking at. `pathDimId` is null
		// because the row is the whole site, and the scope kind says so.
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['hour']      = UniqueScope::HOUR_DAILY;
			$rows[ $index ]['pathDimId'] = null;
		}

		return $rows;
	}

	// ----------------------------------------------------------------- trend

	/**
	 * Views and unique visitors over time.
	 *
	 * @param int         $siteId      Site ID.
	 * @param DateRange   $range       Period.
	 * @param int|null    $pathDimId   Restrict to one page.
	 * @param Granularity $granularity Bucket size. Ignored for an hourly range.
	 *
	 * @return array{labels:string[],views:int[],uniques:int[],hourly:bool,granularity:string}
	 */
	public function trend( int $siteId, DateRange $range, ?int $pathDimId = null, Granularity $granularity = Granularity::Day ): array {
		return $this->remember(
			'trend:' . $granularity->value . ':' . ( $pathDimId ?? '' ),
			$siteId,
			$range,
			function () use ( $siteId, $range, $pathDimId, $granularity ): array {
				// A single day still inside the hourly window gets 24 points. One
				// from three months ago has been compacted to a single row, so it
				// gets one honest point rather than 24 zeroes. Granularity is moot
				// either way: there is only one bucket to offer it on.
				if ( $range->isHourly() && $range->from >= $this->hourlyWindowFrom() ) {
					return $this->hourlyTrend( $siteId, $range, $pathDimId );
				}

				if ( Granularity::Day === $granularity ) {
					return $this->dailyTrend( $siteId, $range, $pathDimId );
				}

				return $this->bucketedTrend( $siteId, $range, $pathDimId, $granularity );
			}
		);
	}

	/**
	 * Twenty-four hourly points for one day.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array{labels:string[],views:int[],uniques:int[],hourly:bool}
	 */
	private function hourlyTrend( int $siteId, DateRange $range, ?int $pathDimId ): array {
		global $wpdb;

		$table = Tables::name( Tables::PAGES_ROLLUP );
		$where = 'siteId = %d AND date = %s';
		$args  = [ $siteId, $range->from ];

		if ( null !== $pathDimId ) {
			$where .= ' AND pathDimId = %d';
			$args[] = $pathDimId;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT hour, COALESCE(SUM(views),0) AS views FROM `$table` WHERE $where GROUP BY hour", $args ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$byHour = [];

		foreach ( (array) $rows as $row ) {
			$byHour[ (int) $row['hour'] ] = (int) $row['views'];
		}

		$labels = [];
		$views  = [];

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$labels[] = sprintf( '%02d:00', $hour );
			// A day that has already been compacted carries hour -1 and cannot
			// answer this. Flat zeroes are the honest answer rather than
			// spreading the daily total across the hours.
			$views[] = isset( $byHour[ Compactor::DAILY_HOUR ] ) ? 0 : ( $byHour[ $hour ] ?? 0 );
		}

		return [
			'labels'      => $labels,
			'views'       => $views,
			// There are no per-hour unique counts to merge, so the series is
			// left empty and the chart drops it rather than drawing a flat line
			// that reads as "nobody was unique".
			'uniques'     => array_fill( 0, 24, 0 ),
			'hourly'      => true,
			'granularity' => Granularity::Day->value,
		];
	}

	/**
	 * One point per day.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array{labels:string[],views:int[],uniques:int[],hourly:bool}
	 */
	private function dailyTrend( int $siteId, DateRange $range, ?int $pathDimId ): array {
		global $wpdb;

		$table = Tables::name( Tables::PAGES_ROLLUP );
		$where = 'siteId = %d AND date BETWEEN %s AND %s';
		$args  = [ $siteId, $range->from, $range->to ];

		if ( null !== $pathDimId ) {
			$where .= ' AND pathDimId = %d';
			$args[] = $pathDimId;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT date, COALESCE(SUM(views),0) AS views FROM `$table` WHERE $where GROUP BY date", $args ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$byDate = [];

		foreach ( (array) $rows as $row ) {
			$byDate[ (string) $row['date'] ] = (int) $row['views'];
		}

		$uniquesByDate = $this->uniquesByDate( $siteId, $range, $pathDimId );

		$labels  = [];
		$views   = [];
		$uniques = [];

		foreach ( $range->dates() as $date ) {
			$labels[]  = $date;
			$views[]   = $byDate[ $date ] ?? 0;
			$uniques[] = $uniquesByDate[ $date ] ?? 0;
		}

		return [
			'labels'      => $labels,
			'views'       => $views,
			'uniques'     => $uniques,
			'hourly'      => false,
			'granularity' => Granularity::Day->value,
		];
	}

	/**
	 * One point per bucket, at any grain coarser than a day.
	 *
	 * Reuses the same per-day query {@see dailyTrend()} runs - day-level rows
	 * are kept for the whole retention window, so a coarser chart is a
	 * re-bucketing of rows that already exist, not a different query.
	 *
	 * @param int         $siteId      Site ID.
	 * @param DateRange   $range       Period.
	 * @param int|null    $pathDimId   Restrict to one page.
	 * @param Granularity $granularity Bucket size.
	 *
	 * @return array{labels:string[],views:int[],uniques:int[],hourly:bool,granularity:string}
	 */
	private function bucketedTrend( int $siteId, DateRange $range, ?int $pathDimId, Granularity $granularity ): array {
		global $wpdb;

		$table = Tables::name( Tables::PAGES_ROLLUP );
		$where = 'siteId = %d AND date BETWEEN %s AND %s';
		$args  = [ $siteId, $range->from, $range->to ];

		if ( null !== $pathDimId ) {
			$where .= ' AND pathDimId = %d';
			$args[] = $pathDimId;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT date, COALESCE(SUM(views),0) AS views FROM `$table` WHERE $where GROUP BY date", $args ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$viewsByBucket = [];

		foreach ( (array) $rows as $row ) {
			$bucket                   = $granularity->bucketKeyFor( (string) $row['date'] );
			$viewsByBucket[ $bucket ] = ( $viewsByBucket[ $bucket ] ?? 0 ) + (int) $row['views'];
		}

		$uniquesByBucket = $this->uniquesByBucket( $siteId, $range, $pathDimId, $granularity );

		$labels  = [];
		$views   = [];
		$uniques = [];

		foreach ( $granularity->bucketsFor( $range ) as $bucket ) {
			$labels[]  = $bucket;
			$views[]   = $viewsByBucket[ $bucket ] ?? 0;
			$uniques[] = $uniquesByBucket[ $bucket ] ?? 0;
		}

		return [
			'labels'      => $labels,
			'views'       => $views,
			'uniques'     => $uniques,
			'hourly'      => false,
			'granularity' => $granularity->value,
		];
	}

	/**
	 * Unique visitors for each bucket in a range, at any grain.
	 *
	 * The grouping key changes, the merge does not: every scope inside a
	 * bucket - whichever days it spans - is merged together in one pass, never
	 * summed from smaller pre-merged figures. Adding up daily uniques to make
	 * a monthly one counts a regular reader once per visit.
	 *
	 * @param int         $siteId      Site ID.
	 * @param DateRange   $range       Period.
	 * @param int|null    $pathDimId   Restrict to one page.
	 * @param Granularity $granularity Bucket size.
	 *
	 * @return array<string,int>
	 */
	private function uniquesByBucket( int $siteId, DateRange $range, ?int $pathDimId, Granularity $granularity ): array {
		$rows             = $this->uniqueRows( $siteId, $range, $pathDimId );
		$byBucket         = [];
		$sketches         = [];
		$importedByBucket = [];

		foreach ( $rows as $row ) {
			$date   = (string) $row['date'];
			$bucket = $granularity->bucketKeyFor( $date );
			$scope  = self::scopeFor( $siteId, $row );

			$byBucket[ $bucket ][] = $scope;

			if ( array_key_exists( 'uniques', $row ) ) {
				$sketches[ $scope->key() ] = $this->readBlob( $row['uniques'] );
			}

			$importedByBucket[ $bucket ] = ( $importedByBucket[ $bucket ] ?? 0 ) + (int) ( $row['importedUniques'] ?? 0 );
		}

		$out = [];

		foreach ( $byBucket as $bucket => $scopes ) {
			$out[ $bucket ] = $this->counter->estimate( $scopes, $sketches ) + ( $importedByBucket[ $bucket ] ?? 0 );
		}

		return $out;
	}

	/**
	 * Unique visitors for each day in a range.
	 *
	 * One pass over the rows, grouped in PHP, with each day merged separately -
	 * a query per day would be a year of queries on a twelve-month chart.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int|null  $pathDimId Restrict to one page.
	 *
	 * @return array<string,int>
	 */
	private function uniquesByDate( int $siteId, DateRange $range, ?int $pathDimId ): array {
		$rows           = $this->uniqueRows( $siteId, $range, $pathDimId );
		$byDate         = [];
		$sketches       = [];
		$importedByDate = [];

		foreach ( $rows as $row ) {
			$date  = (string) $row['date'];
			$scope = self::scopeFor( $siteId, $row );

			$byDate[ $date ][] = $scope;

			if ( array_key_exists( 'uniques', $row ) ) {
				$sketches[ $scope->key() ] = $this->readBlob( $row['uniques'] );
			}

			$importedByDate[ $date ] = ( $importedByDate[ $date ] ?? 0 ) + (int) ( $row['importedUniques'] ?? 0 );
		}

		$out = [];

		foreach ( $byDate as $date => $scopes ) {
			$out[ $date ] = $this->counter->estimate( $scopes, $sketches ) + ( $importedByDate[ $date ] ?? 0 );
		}

		return $out;
	}

	// ----------------------------------------------------------------- pages

	/**
	 * The most-viewed pages.
	 *
	 * @param int         $siteId  Site ID.
	 * @param DateRange   $range   Period.
	 * @param int         $limit   Maximum rows.
	 * @param string|null $include Only paths containing this.
	 * @param string|null $exclude Skip paths containing this.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function topPages( int $siteId, DateRange $range, int $limit = 100, ?string $include = null, ?string $exclude = null ): array {
		return (array) $this->remember(
			'pages:' . $limit . ':' . ( $include ?? '' ) . ':' . ( $exclude ?? '' ),
			$siteId,
			$range,
			function () use ( $siteId, $range, $limit, $include, $exclude ): array {
				global $wpdb;

				$pages      = Tables::name( Tables::PAGES_ROLLUP );
				$dimensions = Tables::name( Tables::DIMENSIONS );

				// Aggregated on the rollup alone and joined afterwards. Joining first
				// put `dimensions.value` - a varchar(500) - in the temp table's group
				// key, so every distinct path carried five hundred bytes through the
				// grouping to produce a row identified by an eight-byte id it already
				// had. Grouping on `pathDimId` and joining the survivors reads the
				// same rows and sorts a fraction of the bytes.
				$where = 'p.siteId = %d AND p.date BETWEEN %s AND %s';
				$args  = [ $siteId, $range->from, $range->to ];

				// The include and exclude filters are `LIKE '%...%'` against that same
				// unindexed varchar, so they cannot use an index wherever they run.
				// Resolved to a set of ids first, they at least run once over the
				// dimensions table rather than once per rollup row considered.
				$ids = $this->pathIdsMatching( $siteId, $include, $exclude );

				if ( null !== $ids ) {
					if ( [] === $ids ) {
						return [];
					}

					$where .= ' AND p.pathDimId IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
					$args   = array_merge( $args, $ids );
				}

				$args[] = max( 1, $limit );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT d.value AS path, agg.pathDimId, agg.postId, agg.views, agg.entrances, agg.exits, agg.bounces, agg.dwell
					FROM (
						SELECT p.pathDimId AS pathDimId, MAX(p.postId) AS postId,
							COALESCE(SUM(p.views),0) AS views,
							COALESCE(SUM(p.entrances),0) AS entrances,
							COALESCE(SUM(p.exits),0) AS exits,
							COALESCE(SUM(p.bounces),0) AS bounces,
							COALESCE(SUM(p.totalDwellMs),0) AS dwell
						FROM `$pages` p
						WHERE $where
						GROUP BY p.pathDimId
						ORDER BY views DESC
						LIMIT %d
					) agg
					INNER JOIN `$dimensions` d ON d.id = agg.pathDimId
					ORDER BY agg.views DESC",
						$args
					),
					ARRAY_A
				);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

				return array_map( [ $this, 'shapePageRow' ], (array) $rows );
			}
		);
	}

	/**
	 * The dimension ids for a list of exact paths.
	 *
	 * By hash, not by value: `dimension_lookup (type, valueHash)` is the only
	 * index the dimensions table has, and a 500-character column is not one to
	 * compare a list against without one.
	 *
	 * Paths with no dimension row are simply absent from the result - a page
	 * nobody has visited has no id, and no rows to trend.
	 *
	 * @param string[] $paths Paths.
	 *
	 * @return int[]
	 */
	private function pathIdsFor( array $paths ): array {
		global $wpdb;

		$hashes = [];

		foreach ( $paths as $path ) {
			$hashes[] = DimensionsService::valueHash( DimensionsService::normalize( (string) $path ) );
		}

		$hashes = array_values( array_unique( $hashes ) );

		if ( [] === $hashes ) {
			return [];
		}

		$dimensions   = Tables::name( Tables::DIMENSIONS );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `$dimensions` WHERE type = %d AND valueHash IN ($placeholders)",
				array_merge( [ DimensionType::Path->value ], $hashes )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Path dimension ids matching the include and exclude filters.
	 *
	 * Null when neither filter is set, which is the ordinary case and means
	 * "do not filter at all" - distinct from an empty array, which means the
	 * filters matched nothing and the answer is no rows.
	 *
	 * @param int         $siteId  Site ID.
	 * @param string|null $include Only paths containing this.
	 * @param string|null $exclude Skip paths containing this.
	 *
	 * @return int[]|null
	 */
	private function pathIdsMatching( int $siteId, ?string $include, ?string $exclude ): ?array {
		global $wpdb;

		unset( $siteId );

		$hasInclude = null !== $include && '' !== $include;
		$hasExclude = null !== $exclude && '' !== $exclude;

		if ( ! $hasInclude && ! $hasExclude ) {
			return null;
		}

		$dimensions = Tables::name( Tables::DIMENSIONS );

		$where = 'type = %d';
		$args  = [ DimensionType::Path->value ];

		if ( $hasInclude ) {
			$where .= ' AND value LIKE %s';
			$args[] = '%' . $wpdb->esc_like( (string) $include ) . '%';
		}

		if ( $hasExclude ) {
			$where .= ' AND value NOT LIKE %s';
			$args[] = '%' . $wpdb->esc_like( (string) $exclude ) . '%';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `$dimensions` WHERE $where", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The figures for one page.
	 *
	 * Returns zeroes rather than null for a page with no traffic in the period:
	 * "nobody came" is a real answer.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $pathDimId Page dimension id.
	 *
	 * @return array<string,int|float>
	 */
	public function pageTotals( int $siteId, DateRange $range, int $pathDimId ): array {
		return (array) $this->remember(
			'page-totals:' . $pathDimId,
			$siteId,
			$range,
			function () use ( $siteId, $range, $pathDimId ): array {
				global $wpdb;

				$table = Tables::name( Tables::PAGES_ROLLUP );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(views),0) AS views, COALESCE(SUM(entrances),0) AS entrances,
						COALESCE(SUM(exits),0) AS exits, COALESCE(SUM(bounces),0) AS bounces,
						COALESCE(SUM(totalDwellMs),0) AS dwell, MAX(postId) AS postId
					FROM `$table` WHERE siteId = %d AND pathDimId = %d AND date BETWEEN %s AND %s",
						$siteId,
						$pathDimId,
						$range->from,
						$range->to
					),
					ARRAY_A
				);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$views     = (int) ( $row['views'] ?? 0 );
				$entrances = (int) ( $row['entrances'] ?? 0 );
				$bounces   = (int) ( $row['bounces'] ?? 0 );
				$dwell     = (int) ( $row['dwell'] ?? 0 );

				return [
					'views'      => $views,
					'uniques'    => $this->uniquesFor( $siteId, $range, $pathDimId ),
					'entrances'  => $entrances,
					'exits'      => (int) ( $row['exits'] ?? 0 ),
					'bounces'    => $bounces,
					'bounceRate' => $entrances > 0 ? $bounces / $entrances * 100 : 0.0,
					'avgDwellMs' => $views > 0 ? (int) round( $dwell / $views ) : 0,
					'postId'     => null !== ( $row['postId'] ?? null ) ? (int) $row['postId'] : 0,
				];
			}
		);
	}

	/**
	 * Views per day for a set of paths, for the comparison chart.
	 *
	 * The caller must have validated the paths: an unvalidated list would let a
	 * request answer "does this path exist on a site you can see the report
	 * for", one guess at a time.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param string[]  $paths  Paths.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pathsTrend( int $siteId, DateRange $range, array $paths ): array {
		global $wpdb;

		$paths = array_values( array_filter( $paths ) );

		if ( [] === $paths ) {
			return [];
		}

		// Resolved to ids first. Matching on `d.value IN (...)` meant an
		// unindexable comparison against a varchar(500) for every rollup row in
		// the range, to identify rows by a value the caller had already been
		// given alongside its id. Hashing instead uses `dimension_lookup`, and
		// the rollup is then filtered on `by_path`.
		$ids = $this->pathIdsFor( $paths );

		if ( [] === $ids ) {
			return [];
		}

		$pages        = Tables::name( Tables::PAGES_ROLLUP );
		$dimensions   = Tables::name( Tables::DIMENSIONS );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$args = array_merge( [ $siteId ], $ids, [ $range->from, $range->to ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agg.date, d.value AS path, agg.views
				FROM (
					SELECT p.date, p.pathDimId AS dimId, COALESCE(SUM(p.views),0) AS views
					FROM `$pages` p
					WHERE p.siteId = %d AND p.pathDimId IN ($placeholders)
						AND p.date BETWEEN %s AND %s
					GROUP BY p.date, p.pathDimId
				) agg
				INNER JOIN `$dimensions` d ON d.id = agg.dimId",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return (array) $rows;
	}

	/**
	 * How visits reached one page.
	 *
	 * @param int       $siteId    Site ID.
	 * @param DateRange $range     Period.
	 * @param int       $pathDimId Page dimension id.
	 * @param int       $limit     Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pageSources( int $siteId, DateRange $range, int $pathDimId, int $limit = 20 ): array {
		global $wpdb;

		$table      = Tables::name( Tables::PAGE_SOURCES_ROLLUP );
		$dimensions = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agg.channel, d.value AS host, agg.views
				FROM (
					SELECT p.channel, p.refHostDimId AS dimId, COALESCE(SUM(p.views),0) AS views
					FROM `$table` p
					WHERE p.siteId = %d AND p.pathDimId = %d AND p.date BETWEEN %s AND %s
					GROUP BY p.channel, p.refHostDimId
					ORDER BY views DESC
					LIMIT %d
				) agg
				LEFT JOIN `$dimensions` d ON d.id = agg.dimId
				ORDER BY agg.views DESC",
				$siteId,
				$pathDimId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $rows;
	}

	/**
	 * The first day per-page sources were recorded.
	 *
	 * The screen uses it to say "collecting since" rather than implying a page
	 * had no referrers before the table existed.
	 *
	 * @param int $siteId Site ID.
	 */
	public function pageSourcesSince( int $siteId ): ?string {
		global $wpdb;

		$table = Tables::name( Tables::PAGE_SOURCES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$date = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(date) FROM `$table` WHERE siteId = %d", $siteId ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_string( $date ) && '' !== $date ? $date : null;
	}

	// --------------------------------------------------------------- sources

	/**
	 * Sessions by channel and referring host.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sources( int $siteId, DateRange $range, int $limit = 100 ): array {
		return (array) $this->remember(
			'sources:' . $limit,
			$siteId,
			$range,
			function () use ( $siteId, $range, $limit ): array {
				global $wpdb;

				$table      = Tables::name( Tables::SOURCES_ROLLUP );
				$dimensions = Tables::name( Tables::DIMENSIONS );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT agg.channel, d.value AS host, agg.sessions, agg.bounces
					FROM (
						SELECT s.channel, s.refHostDimId AS dimId,
							COALESCE(SUM(s.sessions),0) AS sessions,
							COALESCE(SUM(s.bounces),0) AS bounces
						FROM `$table` s
						WHERE s.siteId = %d AND s.date BETWEEN %s AND %s
						GROUP BY s.channel, s.refHostDimId
						ORDER BY sessions DESC
						LIMIT %d
					) agg
					LEFT JOIN `$dimensions` d ON d.id = agg.dimId
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
					$sessions = (int) $row['sessions'];
					$bounces  = (int) $row['bounces'];

					$out[] = [
						'channel'    => (int) $row['channel'],
						'host'       => null !== $row['host'] ? (string) $row['host'] : '',
						'sessions'   => $sessions,
						'bounces'    => $bounces,
						'bounceRate' => $sessions > 0 ? $bounces / $sessions * 100 : 0.0,
					];
				}

				return $out;
			}
		);
	}

	/**
	 * Sessions by channel.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function channels( int $siteId, DateRange $range ): array {
		return (array) $this->remember(
			'channels',
			$siteId,
			$range,
			function () use ( $siteId, $range ): array {
				global $wpdb;

				$table = Tables::name( Tables::SOURCES_ROLLUP );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT channel, COALESCE(SUM(sessions),0) AS sessions, COALESCE(SUM(bounces),0) AS bounces
					FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s
					GROUP BY channel ORDER BY sessions DESC",
						$siteId,
						$range->from,
						$range->to
					),
					ARRAY_A
				);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$out = [];

				foreach ( (array) $rows as $row ) {
					$sessions = (int) $row['sessions'];
					$bounces  = (int) $row['bounces'];

					$out[] = [
						'channel'    => (int) $row['channel'],
						'sessions'   => $sessions,
						'bounces'    => $bounces,
						'bounceRate' => $sessions > 0 ? $bounces / $sessions * 100 : 0.0,
					];
				}

				return $out;
			}
		);
	}

	/**
	 * Sessions by channel and day, for the stacked chart.
	 *
	 * Returned raw: zero-filling and folding the tail into "Other" is the chart
	 * builder's job, and is tested there.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function channelTrend( int $siteId, DateRange $range ): array {
		global $wpdb;

		$table = Tables::name( Tables::SOURCES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, channel, COALESCE(SUM(sessions),0) AS sessions
				FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s
				GROUP BY date, channel",
				$siteId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// --------------------------------------------------------------- devices

	/**
	 * Sessions by browser, operating system or device shape.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param string    $by     'browser', 'os' or 'deviceType'.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function devices( int $siteId, DateRange $range, string $by = 'browser' ): array {
		return (array) $this->remember(
			'devices:' . $by,
			$siteId,
			$range,
			function () use ( $siteId, $range, $by ): array {
				global $wpdb;

				$table = Tables::name( Tables::DEVICES_ROLLUP );

				if ( 'deviceType' === $by ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT deviceType AS label, COALESCE(SUM(sessions),0) AS sessions
						FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s
						GROUP BY deviceType ORDER BY sessions DESC",
							$siteId,
							$range->from,
							$range->to
						),
						ARRAY_A
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

					return (array) $rows;
				}

				$column     = 'os' === $by ? 'osDimId' : 'browserDimId';
				$dimensions = Tables::name( Tables::DIMENSIONS );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT d.value AS label, agg.sessions
					FROM (
						SELECT v.$column AS dimId, COALESCE(SUM(v.sessions),0) AS sessions
						FROM `$table` v
						WHERE v.siteId = %d AND v.date BETWEEN %s AND %s
						GROUP BY v.$column
					) agg
					INNER JOIN `$dimensions` d ON d.id = agg.dimId
					ORDER BY agg.sessions DESC",
						$siteId,
						$range->from,
						$range->to
					),
					ARRAY_A
				);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				return (array) $rows;
			}
		);
	}

	// -------------------------------------------------------------- crawlers

	/**
	 * Requests by crawler.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function crawlers( int $siteId, DateRange $range, int $limit = 100 ): array {
		return (array) $this->remember(
			'crawlers:' . $limit,
			$siteId,
			$range,
			function () use ( $siteId, $range, $limit ): array {
				global $wpdb;

				$table      = Tables::name( Tables::CRAWLERS_ROLLUP );
				$dimensions = Tables::name( Tables::DIMENSIONS );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				return (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT d.value AS label, agg.requests
					FROM (
						SELECT c.crawlerDimId AS dimId, COALESCE(SUM(c.requests),0) AS requests
						FROM `$table` c
						WHERE c.siteId = %d AND c.date BETWEEN %s AND %s
						GROUP BY c.crawlerDimId
						ORDER BY requests DESC
						LIMIT %d
					) agg
					INNER JOIN `$dimensions` d ON d.id = agg.dimId
					ORDER BY agg.requests DESC",
						$siteId,
						$range->from,
						$range->to,
						max( 1, $limit )
					),
					ARRAY_A
				);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		);
	}

	/**
	 * Total crawler requests kept out of the human reports.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 */
	public function crawlerRequests( int $siteId, DateRange $range ): int {
		global $wpdb;

		$table = Tables::name( Tables::CRAWLERS_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(requests),0) FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$range->from,
				$range->to
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * How many distinct crawlers were seen.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 */
	public function crawlerTypes( int $siteId, DateRange $range ): int {
		global $wpdb;

		$table = Tables::name( Tables::CRAWLERS_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT crawlerDimId) FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$range->from,
				$range->to
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// --------------------------------------------------------------- heatmap

	/**
	 * Views by day and hour.
	 *
	 * Clamped to the hourly retention window whatever range is asked for: past
	 * that the rows have been compacted and the hour genuinely is not recorded
	 * any more. The card on screen states the window, so the limit reads as
	 * policy rather than as a broken chart.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function hourOfWeek( int $siteId, DateRange $range ): array {
		global $wpdb;

		$from = max( $range->from, $this->hourlyWindowFrom() );

		if ( $from > $range->to ) {
			return [];
		}

		$table = Tables::name( Tables::PAGES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, hour, COALESCE(SUM(views),0) AS views
				FROM `$table`
				WHERE siteId = %d AND date BETWEEN %s AND %s AND hour <> %d
				GROUP BY date, hour",
				$siteId,
				$from,
				$range->to,
				Compactor::DAILY_HOUR
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The oldest date still recorded hour by hour.
	 *
	 * @param int|null $now Timestamp.
	 */
	public function hourlyWindowFrom( ?int $now = null ): string {
		return ( new Compactor( $this->settings, $this->counter ) )->cutoffDate( $now ?? time() );
	}

	// ----------------------------------------------------------------- posts

	/**
	 * Views for a set of posts, in one query.
	 *
	 * @param int       $siteId  Site ID.
	 * @param int[]     $postIds Post ids.
	 * @param DateRange $range   Period.
	 *
	 * @return array<int,int>
	 */
	public function viewsByPost( int $siteId, array $postIds, DateRange $range ): array {
		global $wpdb;

		$postIds = array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) );

		if ( [] === $postIds ) {
			return [];
		}

		$table        = Tables::name( Tables::PAGES_ROLLUP );
		$placeholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
		$args         = array_merge( [ $siteId, $range->from, $range->to ], $postIds );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT postId, COALESCE(SUM(views),0) AS views
				FROM `$table`
				WHERE siteId = %d AND date BETWEEN %s AND %s AND postId IN ($placeholders)
				GROUP BY postId",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['postId'] ] = (int) $row['views'];
		}

		return $out;
	}

	/**
	 * Views and a daily series for one post.
	 *
	 * @param int       $siteId Site ID.
	 * @param int       $postId Post id.
	 * @param DateRange $range  Period.
	 *
	 * @return array{views:int,uniques:int,series:int[]}
	 */
	public function postStats( int $siteId, int $postId, DateRange $range ): array {
		global $wpdb;

		$table = Tables::name( Tables::PAGES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, COALESCE(SUM(views),0) AS views
				FROM `$table` WHERE siteId = %d AND postId = %d AND date BETWEEN %s AND %s
				GROUP BY date",
				$siteId,
				$postId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$byDate = [];

		foreach ( (array) $rows as $row ) {
			$byDate[ (string) $row['date'] ] = (int) $row['views'];
		}

		$series = [];

		foreach ( $range->dates() as $date ) {
			$series[] = $byDate[ $date ] ?? 0;
		}

		return [
			'views'   => array_sum( $series ),
			'uniques' => $this->postUniques( $siteId, $postId, $range ),
			'series'  => $series,
		];
	}

	/**
	 * Distinct visitors to one post.
	 *
	 * @param int       $siteId Site ID.
	 * @param int       $postId Post id.
	 * @param DateRange $range  Period.
	 */
	private function postUniques( int $siteId, int $postId, DateRange $range ): int {
		global $wpdb;

		$table   = Tables::name( Tables::PAGES_ROLLUP );
		$columns = $this->counter->storesOnRow() ? 'date, hour, pathDimId, uniques, importedUniques' : 'date, hour, pathDimId, importedUniques';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $columns FROM `$table` WHERE siteId = %d AND postId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$postId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$scopes   = [];
		$sketches = [];
		$imported = 0;

		foreach ( (array) $rows as $row ) {
			$scope    = new UniqueScope(
				UniqueScope::KIND_PAGE,
				$siteId,
				(string) $row['date'],
				(int) $row['hour'],
				(int) $row['pathDimId']
			);
			$scopes[] = $scope;

			if ( array_key_exists( 'uniques', $row ) ) {
				$sketches[ $scope->key() ] = $this->readBlob( $row['uniques'] );
			}

			$imported += (int) ( $row['importedUniques'] ?? 0 );
		}

		return $this->counter->estimate( $scopes, $sketches ) + $imported;
	}

	// ----------------------------------------------------------------- misc

	/**
	 * How accurate the unique figures are, in words a report can print.
	 */
	public function uniquesAccuracy(): string {
		return $this->counter->accuracy();
	}

	/**
	 * The unique counter in use.
	 */
	public function counter(): UniqueCounterInterface {
		return $this->counter;
	}

	/**
	 * Shape a page row for the reports.
	 *
	 * @param array<string,mixed> $row Row.
	 *
	 * @return array<string,mixed>
	 */
	private function shapePageRow( array $row ): array {
		$views     = (int) $row['views'];
		$entrances = (int) $row['entrances'];
		$bounces   = (int) $row['bounces'];
		$dwell     = (int) $row['dwell'];

		return [
			'path'       => (string) $row['path'],
			'pathDimId'  => (int) $row['pathDimId'],
			'postId'     => null !== ( $row['postId'] ?? null ) ? (int) $row['postId'] : 0,
			'views'      => $views,
			'entrances'  => $entrances,
			'exits'      => (int) $row['exits'],
			'bounces'    => $bounces,
			'bounceRate' => $entrances > 0 ? $bounces / $entrances * 100 : null,
			'avgDwellMs' => $views > 0 ? (int) round( $dwell / $views ) : 0,
		];
	}

	/**
	 * Read a sketch column, whatever shape the driver returned it in.
	 *
	 * @param mixed $value Column value.
	 */
	private function readBlob( mixed $value ): ?string {
		if ( is_resource( $value ) ) {
			$value = stream_get_contents( $value );
		}

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Serve a finished period from the object cache.
	 *
	 * The counter driver is part of the key: the same range answered by a
	 * sketch and by exact counting are different numbers.
	 *
	 * @param string    $name    Query name.
	 * @param int       $siteId  Site ID.
	 * @param DateRange $range   Period.
	 * @param callable  $compute Producer.
	 */
	private function remember( string $name, int $siteId, DateRange $range, callable $compute ): mixed {
		return ReportCache::remember( $name, $siteId, $range, $compute, [ 'counter' => $this->counter->name() ] );
	}
}
