<?php
/**
 * Folding hourly rows into daily ones.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use DateTimeImmutable;
use DateTimeZone;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Schema\Upsert;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Db;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;
use HonestAnalytics\Uniques\Hll;
use HonestAnalytics\Uniques\UniqueCounterInterface;
use HonestAnalytics\Uniques\UniqueScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Twenty-four rows become one, losing nothing but the hour.
 *
 * The totals are identical afterwards - counters add and sketches merge - you
 * simply stop being able to ask what happened at three on a Tuesday last March.
 * That trade is what keeps a year of history small, and the window is
 * configurable for anyone who wants to make it differently.
 */
final class Compactor {

	/** The hour a compacted daily row carries. */
	public const DAILY_HOUR = -1;

	/**
	 * Distinct values of the paging column folded per transaction.
	 *
	 * With twenty-four hourly rows behind each value, this is a few thousand
	 * rows in memory at a time whatever the dimension cap is set to.
	 */
	private const PAGE_VALUES = 200;

	private Settings $settings;
	private UniqueCounterInterface $counter;
	private DateTimeZone $timezone;

	/**
	 * Scopes waiting to be discarded once the commit has landed.
	 *
	 * @var UniqueScope[]
	 */
	private array $pendingDiscards = [];

	/**
	 * @param Settings               $settings Settings.
	 * @param UniqueCounterInterface $counter  Unique counter.
	 * @param DateTimeZone|null      $timezone Site timezone.
	 */
	public function __construct( Settings $settings, UniqueCounterInterface $counter, ?DateTimeZone $timezone = null ) {
		$this->settings = $settings;
		$this->counter  = $counter;
		$this->timezone = $timezone ?? Timezone::site();
	}

	/**
	 * Compact every table that keeps an hour.
	 *
	 * @param int|null $now Timestamp, defaulting to now.
	 *
	 * @return int Days compacted.
	 */
	public function run( ?int $now = null ): int {
		$cutoff = $this->cutoffDate( $now ?? time() );
		$days   = 0;

		foreach ( [ Tables::PAGES_ROLLUP, Tables::SESSIONS_ROLLUP, Tables::SOURCES_ROLLUP, Tables::EVENTS_ROLLUP ] as $table ) {
			$days += $this->compactTable( $table, $cutoff );
		}

		return $days;
	}

	/**
	 * The oldest date still kept hour by hour.
	 *
	 * The single source of truth for the hourly window: the heatmap asks this
	 * too, so the span it claims to cover cannot drift from the span that
	 * actually exists.
	 *
	 * @param int $now Timestamp.
	 */
	public function cutoffDate( int $now ): string {
		$days = max( 1, $this->settings->hourlyWindowDays );

		return ( new DateTimeImmutable( '@' . $now ) )
			->setTimezone( $this->timezone )
			->modify( '-' . $days . ' days' )
			->format( 'Y-m-d' );
	}

	/**
	 * Compact one table.
	 *
	 * @param string $table  Unprefixed table name.
	 * @param string $cutoff Oldest date to keep hourly.
	 *
	 * @return int Days compacted.
	 */
	private function compactTable( string $table, string $cutoff ): int {
		global $wpdb;

		$name = Tables::name( $table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$days = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT siteId, date FROM `$name` WHERE date < %s AND hour <> %d ORDER BY date ASC LIMIT 200",
				$cutoff,
				self::DAILY_HOUR
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$compacted = 0;

		foreach ( (array) $days as $day ) {
			if ( $this->compactDay( $table, (int) $day['siteId'], (string) $day['date'] ) ) {
				++$compacted;
			}
		}

		return $compacted;
	}

	/**
	 * Compact one site's day in one table.
	 *
	 * @param string $table  Unprefixed table name.
	 * @param int    $siteId Site ID.
	 * @param string $date   Local date.
	 */
	private function compactDay( string $table, int $siteId, string $date ): bool {
		$column = self::pageColumn( $table );

		if ( null === $column ) {
			// Nothing high-cardinality in the group key, so the whole day folds
			// to a handful of rows and paging would only add transactions.
			return $this->compactRange( $table, $siteId, $date, null, 0, 0 );
		}

		$name    = Tables::name( $table );
		$after   = -1;
		$didWork = false;

		// Paged, because the day used to be read whole into PHP: `SELECT *`
		// across every hour and every path, blobs included, merged in memory,
		// then re-inserted a row at a time inside one transaction. At the
		// default dimension cap that is around twenty-four thousand rows, and
		// it scales linearly with the cap until the process runs out of memory
		// - after which the same day is retried identically every night,
		// compaction stalls for good, and the hourly rows never leave.
		//
		// The range is on one column of the grouping key, so every row that
		// folds together stays in the same page. Splitting a group across two
		// pages would produce two daily rows for one key, and the second insert
		// would fail on the unique index.
		while ( true ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from Schema\Tables and an internal whitelist, and every value is a placeholder.
			$values = Db::col(
				$GLOBALS['wpdb']->prepare(
					"SELECT DISTINCT `$column` FROM `$name` WHERE siteId = %d AND date = %s AND `$column` > %d ORDER BY `$column` ASC LIMIT %d",
					$siteId,
					$date,
					$after,
					self::PAGE_VALUES
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( [] === $values ) {
				return $didWork;
			}

			$low  = (int) $values[0];
			$high = (int) $values[ count( $values ) - 1 ];

			if ( $this->compactRange( $table, $siteId, $date, $column, $low, $high ) ) {
				$didWork = true;
			}

			$after = $high;
		}
	}

	/**
	 * Compact one page of a day, in its own transaction.
	 *
	 * @param string      $table  Unprefixed table name.
	 * @param int         $siteId Site ID.
	 * @param string      $date   Local date.
	 * @param string|null $column Grouping column to range on, or null for the whole day.
	 * @param int         $low    Lowest value in the range.
	 * @param int         $high   Highest value in the range.
	 */
	private function compactRange( string $table, int $siteId, string $date, ?string $column, int $low, int $high ): bool {
		global $wpdb;

		$name = Tables::name( $table );

		$range = null === $column ? '' : " AND `$column` BETWEEN $low AND $high";

		// The read is inside the transaction that deletes what it read, and
		// locks those rows. Outside it, everything committed for this
		// (siteId, date) between the SELECT and the DELETE was destroyed
		// without ever being merged: the delete takes the whole day, and the
		// inserts only carry what the earlier snapshot had seen. The writers
		// named above are exactly the ones that produce late rows for an old
		// date, so the window was not theoretical.
		Db::query( 'START TRANSACTION' );

		try {
			// Every row for the day, *including* one that has already been
			// compacted. Rebuilding a day from its hourly rows alone is correct
			// exactly once: an hourly row arriving afterwards - a replayed
			// batch, a recovered spool, seeded history - would otherwise
			// replace the whole day with whatever had just turned up.
			//
			// Db::rows() rather than get_results(), because a failed read
			// answers null and an empty day answers nothing, and treating a
			// lost query as an empty day would compact it to nothing at all.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$rows = Db::rows(
				$wpdb->prepare( "SELECT * FROM `$name` WHERE siteId = %d AND date = %s$range FOR UPDATE", $siteId, $date )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( [] === $rows ) {
				Db::query( 'COMMIT' );

				return false;
			}

			$folded = $this->mergeDay( $table, $rows );
		} catch ( \Throwable $e ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			Log::error( 'Compaction could not read ' . $table . ' ' . $date . ': ' . $e->getMessage() );

			return false;
		}

		return $this->rewriteDay( $table, $name, $siteId, $date, $range, $folded['rows'], $folded['sketches'] );
	}

	/**
	 * Fold a day's rows down to one per grouping key.
	 *
	 * @param string                         $table Unprefixed table name.
	 * @param array<int,array<string,mixed>> $rows  Every row for the day.
	 *
	 * @return array{rows:array<string,array<string,mixed>>,sketches:array<string,array<int,string>>}
	 */
	private function mergeDay( string $table, array $rows ): array {
		$columns = self::groupColumns( $table );
		$sums    = self::counterColumns( $table );

		$merged   = [];
		$sketches = [];

		foreach ( $rows as $row ) {
			$key = [];

			foreach ( $columns as $column ) {
				$key[] = (string) ( $row[ $column ] ?? '' );
			}

			$key = implode( '|', $key );

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ]   = [];
				$sketches[ $key ] = [];

				foreach ( $columns as $column ) {
					$merged[ $key ][ $column ] = $row[ $column ];
				}

				foreach ( $sums as $column ) {
					$merged[ $key ][ $column ] = 0;
				}

				if ( self::hasPostId( $table ) ) {
					$merged[ $key ]['postId'] = null;
				}
			}

			foreach ( $sums as $column ) {
				$value = $row[ $column ] ?? 0;

				$merged[ $key ][ $column ] += is_numeric( $value ) ? ( self::isDecimal( $column ) ? (float) $value : (int) $value ) : 0;
			}

			if ( self::hasPostId( $table ) && null === $merged[ $key ]['postId'] && null !== ( $row['postId'] ?? null ) ) {
				$merged[ $key ]['postId'] = (int) $row['postId'];
			}

			if ( array_key_exists( 'uniques', $row ) ) {
				$blob = $row['uniques'];

				if ( is_resource( $blob ) ) {
					$blob = stream_get_contents( $blob );
				}

				if ( is_string( $blob ) && '' !== $blob ) {
					// The already-compacted row goes first, so its sketch is the
					// base everything else merges into.
					if ( self::DAILY_HOUR === (int) ( $row['hour'] ?? self::DAILY_HOUR ) ) {
						array_unshift( $sketches[ $key ], $blob );
					} else {
						$sketches[ $key ][] = $blob;
					}
				}
			}
		}

		return [
			'rows'     => $merged,
			'sketches' => $sketches,
		];
	}

	/**
	 * Replace a day's rows with the folded ones, inside the open transaction.
	 *
	 * @param string                            $table    Unprefixed table name.
	 * @param string                            $name     Prefixed table name.
	 * @param int                               $siteId   Site ID.
	 * @param string                            $date     Local date.
	 * @param string                            $range    Extra SQL scoping this page, or an empty string.
	 * @param array<string,array<string,mixed>> $merged   Folded rows.
	 * @param array<string,array<int,string>>   $sketches Sketches per folded row.
	 */
	private function rewriteDay( string $table, string $name, int $siteId, string $date, string $range, array $merged, array $sketches ): bool {
		global $wpdb;

		try {
			// Through Db so a failure throws and rolls the day back. A delete
			// that failed quietly, or an insert that did, would commit a day
			// with some of its rows missing - and after a deadlock, which rolls
			// the transaction back by itself, the inserts that followed would
			// each commit alone beside the hourly rows they were meant to
			// replace, doubling the day on the next read.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			// Scoped to the same range the rows were read from, so a page only
			// ever deletes what it is about to replace.
			Db::query( $wpdb->prepare( "DELETE FROM `$name` WHERE siteId = %d AND date = %s$range", $siteId, $date ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $merged as $key => $row ) {
				$insert = $row;

				$insert['hour'] = self::DAILY_HOUR;

				if ( [] !== ( $sketches[ $key ] ?? [] ) ) {
					$insert['uniques'] = Hll::mergeAll(
						$sketches[ $key ],
						max( Hll::MIN_PRECISION, min( Hll::MAX_PRECISION, $this->settings->hllPrecision ) ),
						static function ( string $message ): void {
							Log::warning( 'Skipped an unreadable sketch while compacting: ' . $message );
						}
					)->serialize();
				}

				Db::insert( $name, $insert );
			}

			$this->compactUniqueScopes( $table, $siteId, $date, $merged );

			Db::query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			Log::error( 'Compaction failed for ' . $table . ' ' . $date . ': ' . $e->getMessage() );

			return false;
		}

		return true;
	}

	/**
	 * Re-file unique counters that live outside the row.
	 *
	 * Readers build their scope from the row they found, so from the moment a
	 * day compacts they ask for hour -1. A counter that kept its data under the
	 * real hours would answer zero for every day older than the window.
	 *
	 * @param string                            $table  Unprefixed table name.
	 * @param int                               $siteId Site ID.
	 * @param string                            $date   Local date.
	 * @param array<string,array<string,mixed>> $merged Merged rows.
	 */
	private function compactUniqueScopes( string $table, int $siteId, string $date, array $merged ): void {
		if ( $this->counter->storesOnRow() ) {
			return;
		}

		$kind = match ( $table ) {
			Tables::PAGES_ROLLUP    => UniqueScope::KIND_PAGE,
			Tables::SESSIONS_ROLLUP => UniqueScope::KIND_SESSION,
			default                 => null,
		};

		if ( null === $kind ) {
			return;
		}

		$folded = [];

		foreach ( $merged as $row ) {
			$dimId = UniqueScope::KIND_PAGE === $kind ? (int) ( $row['pathDimId'] ?? 0 ) : null;
			$daily = new UniqueScope( $kind, $siteId, $date, self::DAILY_HOUR, $dimId );

			$hourly = [];

			for ( $hour = 0; $hour < 24; $hour++ ) {
				$hourly[] = new UniqueScope( $kind, $siteId, $date, $hour, $dimId );
			}

			$this->counter->compact( $daily, $hourly );

			$folded = array_merge( $folded, $hourly );
		}

		// Outside the transaction would be safer for the counters and worse for
		// correctness: a rollback would leave the day with neither its hourly
		// counters nor its daily one. They are discarded after the commit
		// instead, and if that fails the figures are still right.
		//
		// Merged, not assigned. This runs once per day per table - four tables
		// by up to two hundred days - and discardFolded() is called once, after
		// the whole run. Overwriting meant every day but the last kept its
		// hourly unique-member rows for ever: the exact-counter table grew
		// without bound on the sites that chose exactness over the storage
		// guarantee, and nothing else would ever sweep them.
		$this->pendingDiscards = array_merge( $this->pendingDiscards, $folded );
	}

	/**
	 * Discard counters folded away by the last run.
	 */
	public function discardFolded(): void {
		if ( [] === $this->pendingDiscards ) {
			return;
		}

		try {
			$this->counter->discardCompacted( $this->pendingDiscards );
		} catch ( \Throwable $e ) {
			// The figures are correct either way; these expire on their own.
			Log::warning( 'Could not discard compacted unique counters: ' . $e->getMessage() );
		}

		$this->pendingDiscards = [];
	}

	/**
	 * The grouping column a day is folded in ranges of, if there is one.
	 *
	 * Must be one of `groupColumns()`, or a page could split a group in two and
	 * produce two daily rows for one key. Must also be the high-cardinality one
	 * to be worth doing: `channel` has a handful of values, so ranging on it
	 * would bound nothing. Tables whose group key holds nothing but `siteId`,
	 * `date` and `source` fold to a few rows a day and are done whole.
	 *
	 * @param string $table Unprefixed table name.
	 */
	private static function pageColumn( string $table ): ?string {
		return match ( $table ) {
			Tables::PAGES_ROLLUP   => 'pathDimId',
			Tables::SOURCES_ROLLUP => 'refHostDimId',
			Tables::EVENTS_ROLLUP  => 'pathDimId',
			default                => null,
		};
	}

	/**
	 * The columns a table groups by when compacting.
	 *
	 * Pages group by path and deliberately not by post: the unique key is
	 * (site, date, hour, path), so grouping by post as well would produce two
	 * rows the index says are one, and the insert would collide.
	 *
	 * `source` is always part of the group, on every table that has the column.
	 * Its absence here would fold an imported row into a native one sharing the
	 * same date and path the first time a day inside the hourly window carries
	 * both - silently merging two provenances into whichever `source` the merge
	 * happened to keep, which breaks both the "native rows are never touched"
	 * promise {@see \HonestAnalytics\Import\ImportSink} makes and the retention
	 * exemption imported rows are supposed to have.
	 *
	 * @param string $table Unprefixed table name.
	 *
	 * @return string[]
	 */
	private static function groupColumns( string $table ): array {
		return match ( $table ) {
			Tables::PAGES_ROLLUP   => [ 'siteId', 'date', 'pathDimId', 'source' ],
			Tables::SOURCES_ROLLUP => [ 'siteId', 'date', 'channel', 'refHostDimId', 'source' ],
			Tables::SESSIONS_ROLLUP => [ 'siteId', 'date', 'source' ],
			Tables::EVENTS_ROLLUP  => [ 'siteId', 'date', 'eventNameDimId', 'pathDimId' ],
			default                => [ 'siteId', 'date' ],
		};
	}

	/**
	 * The counter columns a table sums when compacting.
	 *
	 * `importedUniques` is a counter here even though it is never added to by
	 * the drain: an imported day already sits on a single daily row, and when
	 * native hourly rows share its date the fold rebuilds that row too. A column
	 * not in this list is not carried across, so leaving it out silently reset
	 * every imported visitor count to zero the day it left the hourly window.
	 *
	 * @param string $table Unprefixed table name.
	 *
	 * @return string[]
	 */
	private static function counterColumns( string $table ): array {
		return match ( $table ) {
			Tables::PAGES_ROLLUP    => [ 'views', 'totalDwellMs', 'entrances', 'exits', 'bounces', 'importedUniques' ],
			Tables::SOURCES_ROLLUP  => [ 'sessions', 'bounces' ],
			Tables::EVENTS_ROLLUP   => [ 'hits', 'sessions', 'sumValue' ],
			Tables::SESSIONS_ROLLUP => [ 'sessions', 'bounces', 'totalDurationMs', 'totalPageviews', 'importedUniques' ],
			default                 => [],
		};
	}

	/**
	 * Whether a table carries a post id worth preserving.
	 *
	 * @param string $table Unprefixed table name.
	 */
	private static function hasPostId( string $table ): bool {
		return Tables::PAGES_ROLLUP === $table;
	}

	/**
	 * Whether a counter column holds a decimal.
	 *
	 * An int cast would round every event's value down to the pound on the day
	 * it compacted.
	 *
	 * @param string $column Column name.
	 */
	private static function isDecimal( string $column ): bool {
		return in_array( $column, [ 'sumValue', 'value', 'conversions' ], true );
	}
}
