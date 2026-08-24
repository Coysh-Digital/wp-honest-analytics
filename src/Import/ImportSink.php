<?php
/**
 * Where imported days are written.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Dimensions\DimensionCapper;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only thing that writes imported analytics.
 *
 * Importers read; this writes. Keeping the two apart is what makes provenance,
 * deduplication and the timezone somebody else's problem exactly once instead
 * of three times.
 *
 * **Idempotency is by construction, not by arithmetic.** A day is written whole:
 * every row this source already had for that date is deleted first, then the
 * new rows are inserted. Run the same import twice and the second run replaces
 * the first rather than adding to it. That is the one property that matters
 * most here - an import that doubles somebody's history on a retry is worse
 * than one that fails - and it is far easier to be sure of than a pile of
 * incremental upserts.
 *
 * Native rows are never touched. They carry `source = 'native'`, which is part
 * of the unique key, so an import for a day the plugin also measured natively
 * sits alongside it rather than on top of it. Whether that double counts is a
 * question for the overlap check before the import starts, not for this class.
 */
final class ImportSink {

	/**
	 * The tables an import writes, in the order they are cleared.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return [
			Tables::PAGES_ROLLUP,
			Tables::SESSIONS_ROLLUP,
			Tables::SOURCES_ROLLUP,
			Tables::DEVICES_ROLLUP,
			Tables::GEO_ROLLUP,
			Tables::DAILY_UNIQUES,
		];
	}

	/**
	 * @param DimensionCapper $capper Dimension resolution, with the cardinality cap.
	 */
	public function __construct( private DimensionCapper $capper ) {
	}

	/**
	 * Write one day, replacing anything this source wrote for it before.
	 *
	 * The day another source owned is displaced here rather than by the caller,
	 * and the coverage rows move in the same transaction as the data. Done
	 * outside it, a source that started failing part way through an eighteen
	 * month replace left the previous import's days deleted with nothing put
	 * back, and a crash between the write and the coverage record left written
	 * rows that no clash check could see - which is the double count the
	 * coverage table exists to prevent.
	 *
	 * @param int         $siteId   Site.
	 * @param string      $source   One of the ImportSource constants.
	 * @param DayBucket   $bucket   The day.
	 * @param Coverage    $coverage Coverage table, written inside the transaction.
	 * @param int         $importId The job doing the writing.
	 * @param string|null $owner    A source whose day this one replaces, if any.
	 *
	 * @return int Rows written.
	 */
	public function write( int $siteId, string $source, DayBucket $bucket, Coverage $coverage, int $importId, ?string $owner = null ): int {
		global $wpdb;

		if ( ImportSource::NATIVE === $source || ! ImportSource::isValid( $source ) ) {
			throw new \InvalidArgumentException( 'Refusing to write imported data under an invalid source.' );
		}

		$date = $bucket->date;
		$rows = 0;

		Db::query( 'START TRANSACTION' );

		try {
			if ( null !== $owner ) {
				$this->clearDay( $siteId, $owner, $date );
				$coverage->forget( $siteId, $owner, $date );
			}

			$this->clearDay( $siteId, $source, $date );

			$rows += $this->writePages( $siteId, $source, $bucket );
			$rows += $this->writeTotals( $siteId, $source, $bucket );
			$rows += $this->writeDailyUniques( $siteId, $source, $bucket );
			$rows += $this->writeSources( $siteId, $source, $bucket );
			$rows += $this->writeDevices( $siteId, $source, $bucket );
			$rows += $this->writeCountries( $siteId, $source, $bucket );

			$coverage->record( $siteId, $source, $importId, $date, $rows );

			Db::query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			throw $e;
		}

		return $rows;
	}

	/**
	 * Record a day that had no traffic in it.
	 *
	 * A day with nothing in it is still a day that has been imported, and
	 * recording it stops a second run offering to fetch it again. It can still
	 * displace another source's day, and that displacement has to be atomic
	 * with the record of it for the same reason every other day's is.
	 *
	 * @param int         $siteId   Site.
	 * @param string      $source   One of the ImportSource constants.
	 * @param string      $date     Y-m-d.
	 * @param Coverage    $coverage Coverage table.
	 * @param int         $importId The job doing the writing.
	 * @param string|null $owner    A source whose day this one replaces, if any.
	 */
	public function writeEmptyDay( int $siteId, string $source, string $date, Coverage $coverage, int $importId, ?string $owner = null ): void {
		global $wpdb;

		if ( ImportSource::NATIVE === $source || ! ImportSource::isValid( $source ) ) {
			throw new \InvalidArgumentException( 'Refusing to write imported data under an invalid source.' );
		}

		Db::query( 'START TRANSACTION' );

		try {
			if ( null !== $owner ) {
				$this->clearDay( $siteId, $owner, $date );
				$coverage->forget( $siteId, $owner, $date );
			}

			$this->clearDay( $siteId, $source, $date );
			$coverage->record( $siteId, $source, $importId, $date, 0 );

			Db::query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			throw $e;
		}
	}

	/**
	 * Remove everything this source wrote for a day.
	 *
	 * Public because removing an import is the same operation as replacing one,
	 * and because a user who imported the wrong property should be able to undo
	 * it without a database client.
	 *
	 * @param int    $siteId Site.
	 * @param string $source Source.
	 * @param string $date   Y-m-d.
	 */
	public function clearDay( int $siteId, string $source, string $date ): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$name = Tables::name( $table );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
			Db::query(
				$wpdb->prepare(
					"DELETE FROM `$name` WHERE siteId = %d AND source = %s AND date = %s",
					$siteId,
					$source,
					$date
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Per-page rows.
	 *
	 * @param int       $siteId Site.
	 * @param string    $source Source.
	 * @param DayBucket $bucket Day.
	 */
	private function writePages( int $siteId, string $source, DayBucket $bucket ): int {
		$rows = [];

		foreach ( $bucket->pages() as $path => $page ) {
			$rows[] = [
				'siteId'          => $siteId,
				'date'            => $bucket->date,
				'hour'            => DayBucket::DAILY_HOUR,
				'pathDimId'       => $this->capper->resolve( $siteId, $bucket->date, DimensionType::Path, (string) $path ),
				'postId'          => $page['postId'],
				'source'          => $source,
				'views'           => $page['views'],
				'uniques'         => null,
				'importedUniques' => $page['visitors'],
				'totalDwellMs'    => $page['dwellMs'],
				'entrances'       => $page['entrances'],
				'exits'           => $page['exits'],
				'bounces'         => $page['bounces'],
			];
		}

		return $this->insert( Tables::PAGES_ROLLUP, $rows );
	}

	/**
	 * The day's imported visitor count, for the site-wide daily row.
	 *
	 * @param int       $siteId Site.
	 * @param string    $source Source.
	 * @param DayBucket $bucket Day.
	 */
	private function writeDailyUniques( int $siteId, string $source, DayBucket $bucket ): int {
		if ( 0 === $bucket->visitors() ) {
			return 0;
		}

		// A count, not a sketch, and added to the estimate rather than merged
		// into it - the same rule ADR 54 sets for the per-path rows. Turning a
		// visitor number back into a sketch would mean inventing the identities
		// it was built from, and a sketch of invented identities merges
		// convincingly and is a lie.
		return $this->insert(
			Tables::DAILY_UNIQUES,
			[
				[
					'siteId'          => $siteId,
					'date'            => $bucket->date,
					'source'          => $source,
					'uniques'         => null,
					'importedUniques' => $bucket->visitors(),
				],
			]
		);
	}

	/**
	 * The day's totals, as one daily row.
	 *
	 * @param int       $siteId Site.
	 * @param string    $source Source.
	 * @param DayBucket $bucket Day.
	 */
	private function writeTotals( int $siteId, string $source, DayBucket $bucket ): int {
		if ( 0 === $bucket->sessions() && 0 === $bucket->visitors() && 0 === $bucket->pageviews() ) {
			return 0;
		}

		return $this->insert(
			Tables::SESSIONS_ROLLUP,
			[
				[
					'siteId'          => $siteId,
					'date'            => $bucket->date,
					'hour'            => DayBucket::DAILY_HOUR,
					'source'          => $source,
					'sessions'        => $bucket->sessions(),
					'bounces'         => $bucket->bounces(),
					'totalDurationMs' => $bucket->durationMs(),
					'totalPageviews'  => $bucket->pageviews(),
					'uniques'         => null,
					'importedUniques' => $bucket->visitors(),
				],
			]
		);
	}

	/**
	 * Channel and referring-host rows.
	 *
	 * @param int       $siteId Site.
	 * @param string    $source Source.
	 * @param DayBucket $bucket Day.
	 */
	private function writeSources( int $siteId, string $source, DayBucket $bucket ): int {
		$rows = [];

		foreach ( $bucket->sources() as $row ) {
			$rows[] = [
				'siteId'       => $siteId,
				'date'         => $bucket->date,
				'hour'         => DayBucket::DAILY_HOUR,
				'channel'      => $row['channel'],
				'refHostDimId' => '' === $row['host']
					? 0
					: $this->capper->resolve( $siteId, $bucket->date, DimensionType::ReferrerHost, $row['host'] ),
				'source'       => $source,
				'sessions'     => $row['sessions'],
				'bounces'      => $row['bounces'],
			];
		}

		return $this->insert( Tables::SOURCES_ROLLUP, $rows );
	}

	/**
	 * Browser, operating system and device rows.
	 *
	 * @param int       $siteId Site.
	 * @param string    $source Source.
	 * @param DayBucket $bucket Day.
	 */
	private function writeDevices( int $siteId, string $source, DayBucket $bucket ): int {
		$rows = [];

		foreach ( $bucket->devices() as $row ) {
			$rows[] = [
				'siteId'       => $siteId,
				'date'         => $bucket->date,
				'browserDimId' => '' === $row['browser']
					? 0
					: $this->capper->resolve( $siteId, $bucket->date, DimensionType::Browser, $row['browser'] ),
				'browserMajor' => $row['browserMajor'],
				'osDimId'      => '' === $row['os']
					? 0
					: $this->capper->resolve( $siteId, $bucket->date, DimensionType::Os, $row['os'] ),
				'deviceType'   => $row['deviceType'],
				'source'       => $source,
				'sessions'     => $row['sessions'],
			];
		}

		return $this->insert( Tables::DEVICES_ROLLUP, $rows );
	}

	/**
	 * Country rows.
	 *
	 * Region is left at zero. No source imported here reports one the plugin
	 * would trust, and inventing a region from a country would be making
	 * detail up.
	 *
	 * @param int       $siteId Site.
	 * @param string    $source Source.
	 * @param DayBucket $bucket Day.
	 */
	private function writeCountries( int $siteId, string $source, DayBucket $bucket ): int {
		$rows = [];

		foreach ( $bucket->countries() as $code => $sessions ) {
			$rows[] = [
				'siteId'      => $siteId,
				'date'        => $bucket->date,
				'countryCode' => (string) $code,
				'regionDimId' => 0,
				'source'      => $source,
				'sessions'    => $sessions,
			];
		}

		return $this->insert( Tables::GEO_ROLLUP, $rows );
	}

	/**
	 * Insert rows in bulk.
	 *
	 * One statement per few hundred rows rather than one per row: an import is
	 * thousands of days wide, and a round trip each would make a shared host
	 * time out on something that should take seconds.
	 *
	 * @param string                         $table Unprefixed table name.
	 * @param array<int,array<string,mixed>> $rows  Rows, all with the same columns.
	 */
	private function insert( string $table, array $rows ): int {
		global $wpdb;

		if ( [] === $rows ) {
			return 0;
		}

		$name    = Tables::name( $table );
		$columns = array_keys( $rows[0] );
		$written = 0;

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$placeholders = [];
			$values       = [];

			foreach ( $chunk as $row ) {
				$slots = [];

				foreach ( $columns as $column ) {
					$value = $row[ $column ] ?? null;

					if ( null === $value ) {
						$slots[] = 'NULL';

						continue;
					}

					$slots[]  = is_int( $value ) ? '%d' : '%s';
					$values[] = $value;
				}

				$placeholders[] = '(' . implode( ',', $slots ) . ')';
			}

			$columnList = '`' . implode( '`,`', array_map( static fn ( $c ): string => str_replace( '`', '', (string) $c ), $columns ) ) . '`';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and the caller's own column list, and every value is a placeholder.
			Db::query(
				$wpdb->prepare(
					"INSERT INTO `$name` ($columnList) VALUES " . implode( ',', $placeholders ),
					$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			// Db::query() rather than a warning and `continue`. This runs inside
			// the transaction write() opened, and a lock-wait timeout rolls back
			// only the statement that hit it - so swallowing one meant
			// committing a day whose clearDay() deletion had already happened
			// and whose replacement rows had not. ImportRunner then recorded
			// coverage for it and the day was never retried: a hole in the
			// history that nothing would ever fill.
			$written += count( $chunk );
		}

		return $written;
	}
}
