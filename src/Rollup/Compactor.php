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
		global $wpdb;

		$name    = Tables::name( $table );
		$columns = self::groupColumns( $table );
		$sums    = self::counterColumns( $table );

		// Every row for the day, *including* one that has already been
		// compacted. Rebuilding a day from its hourly rows alone is correct
		// exactly once: an hourly row arriving afterwards - a replayed batch, a
		// recovered spool, seeded history - would otherwise replace the whole
		// day with whatever had just turned up.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$name` WHERE siteId = %d AND date = %s", $siteId, $date ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( [] === (array) $rows ) {
			return false;
		}

		$merged   = [];
		$sketches = [];

		foreach ( (array) $rows as $row ) {
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM `$name` WHERE siteId = %d AND date = %s", $siteId, $date )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->insert( $name, $insert );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			$this->compactUniqueScopes( $table, $siteId, $date, $merged );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'COMMIT' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$this->pendingDiscards = $folded;
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
	 * @param string $table Unprefixed table name.
	 *
	 * @return string[]
	 */
	private static function counterColumns( string $table ): array {
		return match ( $table ) {
			Tables::PAGES_ROLLUP    => [ 'views', 'totalDwellMs', 'entrances', 'exits', 'bounces' ],
			Tables::SOURCES_ROLLUP  => [ 'sessions', 'bounces' ],
			Tables::EVENTS_ROLLUP   => [ 'hits', 'sessions', 'sumValue' ],
			Tables::SESSIONS_ROLLUP => [ 'sessions', 'bounces', 'totalDurationMs', 'totalPageviews' ],
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
