<?php
/**
 * Which days have already been imported, and by whom.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use DateTimeImmutable;
use DateTimeZone;
use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per imported day, and the reason two imports never double a figure.
 *
 * Somebody with four years of WP Statistics and three years of GA4 has an
 * eighteen-month overlap, and importing both without asking would silently
 * double every number in it. This is what makes the question answerable in one
 * indexed lookup rather than by scanning the rollups: "which days does this
 * range already contain, and which source put them there".
 *
 * A day covered by the *same* source is not an overlap. It is a re-run, and
 * re-running is safe - the sink replaces a day rather than adding to it - so
 * those days are simply imported again.
 */
final class Coverage {

	/**
	 * Which days in a range are already covered, and by which source.
	 *
	 * @param int    $siteId Site.
	 * @param string $from   Y-m-d, inclusive.
	 * @param string $to     Y-m-d, inclusive.
	 *
	 * @return array<string,string> date => source
	 */
	public function inRange( int $siteId, string $from, string $to ): array {
		global $wpdb;

		$table = Tables::name( Tables::IMPORT_COVERAGE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The coverage table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, source FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['date'] ] = (string) $row['source'];
		}

		return $out;
	}

	/**
	 * The days in a range another source has already claimed.
	 *
	 * What the wizard puts in front of somebody before they choose between
	 * skipping the clash and replacing it.
	 *
	 * @param int    $siteId Site.
	 * @param string $source The source about to import.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 *
	 * @return array<string,string> date => the other source
	 */
	public function clashes( int $siteId, string $source, string $from, string $to ): array {
		return array_filter(
			$this->inRange( $siteId, $from, $to ),
			static fn ( string $owner ): bool => $owner !== $source
		);
	}

	/**
	 * A summary of the clash, for the screen.
	 *
	 * @param int    $siteId Site.
	 * @param string $source The source about to import.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 *
	 * @return array<string,array{days:int,from:string,to:string}> keyed by the other source
	 */
	public function clashSummary( int $siteId, string $source, string $from, string $to ): array {
		$clashes = $this->clashes( $siteId, $source, $from, $to );

		ksort( $clashes );

		$out = [];

		foreach ( $clashes as $date => $owner ) {
			if ( ! isset( $out[ $owner ] ) ) {
				$out[ $owner ] = [
					'days' => 0,
					'from' => $date,
					'to'   => $date,
				];
			}

			++$out[ $owner ]['days'];
			$out[ $owner ]['to'] = $date;
		}

		return $out;
	}

	/**
	 * Record that a day has been imported.
	 *
	 * Replaces whatever was there, because the day itself has just been
	 * replaced. The unique key on (siteId, source, date) is what makes this an
	 * upsert rather than a growing pile of duplicates.
	 *
	 * @param int    $siteId   Site.
	 * @param string $source   Source.
	 * @param int    $importId The job that did it.
	 * @param string $date     Y-m-d.
	 * @param int    $records  Rows written for the day.
	 */
	public function record( int $siteId, string $source, int $importId, string $date, int $records ): void {
		global $wpdb;

		$table = Tables::name( Tables::IMPORT_COVERAGE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The coverage table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$table` (siteId, source, importId, date, records) VALUES (%d, %s, %d, %s, %d)
				ON DUPLICATE KEY UPDATE importId = VALUES(importId), records = VALUES(records)",
				$siteId,
				$source,
				$importId,
				$date,
				$records
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Forget the coverage for one day of one source.
	 *
	 * Used when a source's day is replaced by another source, so that the
	 * record of who owns that day follows the data rather than contradicting it.
	 *
	 * @param int    $siteId Site.
	 * @param string $source Source.
	 * @param string $date   Y-m-d.
	 */
	public function forget( int $siteId, string $source, string $date ): void {
		global $wpdb;

		$table = Tables::name( Tables::IMPORT_COVERAGE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The coverage table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `$table` WHERE siteId = %d AND source = %s AND date = %s",
				$siteId,
				$source,
				$date
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * What one source covers, as a range and a count.
	 *
	 * @param int    $siteId Site.
	 * @param string $source Source.
	 *
	 * @return array{days:int,from:string,to:string,records:int}
	 */
	public function forSource( int $siteId, string $source ): array {
		global $wpdb;

		$table = Tables::name( Tables::IMPORT_COVERAGE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The coverage table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS days, MIN(date) AS dateFrom, MAX(date) AS dateTo, COALESCE(SUM(records),0) AS records
				FROM `$table` WHERE siteId = %d AND source = %s",
				$siteId,
				$source
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [
			'days'    => (int) ( $row['days'] ?? 0 ),
			'from'    => (string) ( $row['dateFrom'] ?? '' ),
			'to'      => (string) ( $row['dateTo'] ?? '' ),
			'records' => (int) ( $row['records'] ?? 0 ),
		];
	}

	/**
	 * Every calendar date in a range, inclusive.
	 *
	 * Built in UTC deliberately. These are calendar labels rather than moments,
	 * and stepping a day at a time through a timezone with daylight saving is
	 * how a range quietly loses or repeats the day the clocks change.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 *
	 * @return string[]
	 */
	public static function days( string $from, string $to ): array {
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new DateTimeZone( 'UTC' ) );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, new DateTimeZone( 'UTC' ) );

		if ( false === $start || false === $end || $end < $start ) {
			return [];
		}

		$out    = [];
		$cursor = $start;

		// A hard ceiling, because a mistyped year should not become a loop that
		// allocates a million strings. Thirty years is more history than any
		// analytics plugin has.
		for ( $guard = 0; $guard < 11000 && $cursor <= $end; $guard++ ) {
			$out[]  = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+1 day' );
		}

		return $out;
	}

	/**
	 * How many days a range covers.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function dayCount( string $from, string $to ): int {
		return count( self::days( $from, $to ) );
	}
}
