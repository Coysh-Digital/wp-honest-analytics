<?php
/**
 * Building the site-wide daily uniques rows from the per-path ones.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Plugin;
use HonestAnalytics\Support\Db;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Uniques\Hll;
use HonestAnalytics\Uniques\UniqueScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A one-off pass over the history a site already has.
 *
 * `honest_daily_uniques` holds one sketch per site per day, and the drain
 * writes it as the hits arrive. Everything captured before schema 7 has only
 * the per-path rows, so without this every historical day would answer "no
 * visitors" - which is a worse answer than the slow one it replaces.
 *
 * The sketches are **merged, never recomputed**. They cannot be recomputed: the
 * salt that produced a day's hashes is destroyed that night (ADR 7), so the
 * sketch written on the day is the only record of who was there. Merging is
 * exactly what the reports did on every render before this table existed; this
 * does it once and writes the answer down.
 *
 * A day at a time. The memory ceiling is one site-day of sketches rather than
 * the whole table, and a run that is killed part way leaves the days it
 * finished alone - the version option is only bumped when the whole upgrade
 * succeeded, so an interrupted run is retried from the beginning and the days
 * it already wrote are simply written again with the same answer.
 */
final class DailyUniquesBackfill {

	/**
	 * Site-days handled per query when finding work.
	 */
	private const PAGE = 500;

	/**
	 * Build every missing row.
	 *
	 * @throws \RuntimeException When a read or a write fails.
	 */
	public function run(): void {
		$counter = Plugin::instance()->uniques();

		foreach ( $this->days() as $day ) {
			$this->buildDay( (int) $day['siteId'], (string) $day['date'], $counter->storesOnRow() );
		}
	}

	/**
	 * Every (site, date) that has page rows, oldest first.
	 *
	 * @return array<int,array{siteId:int,date:string}>
	 */
	private function days(): array {
		global $wpdb;

		$pages = Tables::name( Tables::PAGES_ROLLUP );
		$out   = [];

		// MySQL will not compare a DATE against '', so the walk starts at the
		// earliest date the type can hold rather than at an empty string.
		$after = '1000-01-01';

		while ( true ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier comes from Schema\Tables and every value is a placeholder.
			$rows = Db::rows(
				$wpdb->prepare(
					"SELECT DISTINCT siteId, date FROM `$pages` WHERE date > %s ORDER BY date ASC LIMIT %d",
					$after,
					self::PAGE
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( [] === $rows ) {
				return $out;
			}

			foreach ( $rows as $row ) {
				$out[] = [
					'siteId' => (int) $row['siteId'],
					'date'   => (string) $row['date'],
				];
			}

			$after = (string) $rows[ count( $rows ) - 1 ]['date'];
		}
	}

	/**
	 * Build one site's day.
	 *
	 * @param int    $siteId      Site ID.
	 * @param string $date        Local date.
	 * @param bool   $storesOnRow Whether the counter keeps its sketch on the row.
	 */
	private function buildDay( int $siteId, string $date, bool $storesOnRow ): void {
		global $wpdb;

		$pages = Tables::name( Tables::PAGES_ROLLUP );
		$daily = Tables::name( Tables::DAILY_UNIQUES );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from Schema\Tables and every value is a placeholder.
		$existing = Db::col(
			$wpdb->prepare( "SELECT id FROM `$daily` WHERE siteId = %d AND date = %s LIMIT 1", $siteId, $date )
		);

		if ( [] !== $existing ) {
			// Already built, by an earlier run or by the drain.
			return;
		}

		$rows = Db::rows(
			$wpdb->prepare(
				"SELECT source, uniques, importedUniques FROM `$pages` WHERE siteId = %d AND date = %s",
				$siteId,
				$date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( [] === $rows ) {
			return;
		}

		// Grouped by provenance, because the rows are: a native sketch and an
		// imported count for the same day are two rows here, exactly as they
		// are in the per-path table, so that retention and the overlap check
		// can still tell them apart (ADR 53).
		$bySource = [];

		foreach ( $rows as $row ) {
			$source = (string) ( $row['source'] ?? ImportSource::NATIVE );

			if ( ! isset( $bySource[ $source ] ) ) {
				$bySource[ $source ] = [
					'sketches' => [],
					'imported' => 0,
				];
			}

			$bySource[ $source ]['imported'] += (int) ( $row['importedUniques'] ?? 0 );

			$blob = $row['uniques'] ?? null;

			if ( is_resource( $blob ) ) {
				$blob = stream_get_contents( $blob );
			}

			if ( $storesOnRow && is_string( $blob ) && '' !== $blob ) {
				$bySource[ $source ]['sketches'][] = $blob;
			}
		}

		foreach ( $bySource as $source => $group ) {
			$sketch = null;

			if ( [] !== $group['sketches'] ) {
				$sketch = Hll::mergeAll(
					$group['sketches'],
					max( Hll::MIN_PRECISION, min( Hll::MAX_PRECISION, Plugin::instance()->settings()->hllPrecision ) ),
					static function ( string $message ): void {
						Log::warning( 'Skipped an unreadable sketch while building the daily uniques: ' . $message );
					}
				)->serialize();
			}

			Db::insert(
				$daily,
				[
					'siteId'          => $siteId,
					'date'            => $date,
					'source'          => (string) $source,
					'uniques'         => $sketch,
					'importedUniques' => $group['imported'],
				]
			);
		}

		// The exact counter keeps its members in a table of its own rather than
		// on the row, so the site-wide scope has to gain the day's members too.
		if ( ! $storesOnRow ) {
			$this->copyMembers( $siteId, $date );
		}
	}

	/**
	 * Point the exact counter's members at the site-wide scope as well.
	 *
	 * @param int    $siteId Site ID.
	 * @param string $date   Local date.
	 */
	private function copyMembers( int $siteId, string $date ): void {
		global $wpdb;

		$members = Tables::name( Tables::UNIQUE_MEMBERS );
		$scope   = new UniqueScope( UniqueScope::KIND_SITE, $siteId, $date, UniqueScope::HOUR_DAILY );

		// INSERT IGNORE, because the unique key on (scopeKey, visitorHash) is
		// what makes a visitor seen on four pages one visitor here.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier comes from Schema\Tables and every value is a placeholder.
		Db::query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `$members` (scopeKey, siteId, date, visitorHash)
				SELECT %s, siteId, date, visitorHash FROM `$members`
				WHERE siteId = %d AND date = %s AND scopeKey <> %s",
				$scope->key(),
				$siteId,
				$date,
				$scope->key()
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
