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
use HonestAnalytics\Support\Log;

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
	 * @param int       $siteId Site.
	 * @param string    $source One of the ImportSource constants.
	 * @param DayBucket $bucket The day.
	 *
	 * @return int Rows written.
	 */
	public function write( int $siteId, string $source, DayBucket $bucket ): int {
		global $wpdb;

		if ( ImportSource::NATIVE === $source || ! ImportSource::isValid( $source ) ) {
			throw new \InvalidArgumentException( 'Refusing to write imported data under an invalid source.' );
		}

		$date = $bucket->date;
		$rows = 0;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			$this->clearDay( $siteId, $source, $date );

			$rows += $this->writePages( $siteId, $source, $bucket );
			$rows += $this->writeTotals( $siteId, $source, $bucket );
			$rows += $this->writeSources( $siteId, $source, $bucket );
			$rows += $this->writeDevices( $siteId, $source, $bucket );
			$rows += $this->writeCountries( $siteId, $source, $bucket );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			throw $e;
		}

		return $rows;
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
			$wpdb->query(
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
	 * The day's one sessions row.
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
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `$name` ($columnList) VALUES " . implode( ',', $placeholders ),
					$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			if ( false === $result ) {
				Log::warning( 'An imported batch could not be written: ' . $wpdb->last_error );

				continue;
			}

			$written += count( $chunk );
		}

		return $written;
	}
}
