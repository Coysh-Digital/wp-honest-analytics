<?php
/**
 * Dimension lookup and creation.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Dimensions;

use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Support\Db;
use RuntimeException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a dimension type name for the log; the drain catches, rolls back and logs, and never prints it.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared string table.
 *
 * Every rollup stores integers. A path, a referrer host, a browser name and a
 * campaign all resolve to a row here first, which is what keeps a busy site's
 * storage proportional to how many distinct things it has rather than to how
 * often they happen.
 */
final class DimensionsService {

	/** Longest value stored. Anything past this is truncated, not rejected. */
	public const MAX_VALUE_LENGTH = 500;

	/**
	 * Per-request memo, keyed "type:hash".
	 *
	 * A null entry means "looked, and it is not there" - which is an answer
	 * worth remembering too. Memoising only the hits meant that once the
	 * cardinality cap engaged, every further occurrence of an uncapped value
	 * cost a fresh SELECT: the drain asking the same question a few thousand
	 * times and getting the same no.
	 *
	 * @var array<string,int|null>
	 */
	private array $memo = [];

	/**
	 * Find or create the id for a value.
	 *
	 * @param DimensionType $type  Dimension type.
	 * @param string        $value Value.
	 */
	public function getOrCreateId( DimensionType $type, string $value ): int {
		global $wpdb;

		$value = self::normalize( $value );
		$hash  = self::valueHash( $value );
		$key   = $type->value . ':' . $hash;

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$existing = $this->lookup( $type, $hash );

		if ( null !== $existing ) {
			$this->memo[ $key ] = $existing;

			return $existing;
		}

		$table = Tables::name( Tables::DIMENSIONS );

		// INSERT IGNORE rather than a check-then-insert: two workers seeing the
		// same new path in the same millisecond is ordinary, and losing that
		// race should cost a re-read rather than an exception on a page request.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		Db::query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `$table` (type, valueHash, value, firstSeen) VALUES (%d, %s, %s, %s)",
				$type->value,
				$hash,
				$value,
				gmdate( 'Y-m-d' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$id = (int) $wpdb->insert_id;

		if ( $id <= 0 ) {
			$id = (int) $this->lookup( $type, $hash );
		}

		if ( $id <= 0 ) {
			// Nothing inserted and nothing found. Returning 0 would be taken
			// for a dimension id by every caller: DbRollupSink writes it
			// straight into a rollup key, so a whole batch of views would be
			// filed under a row that names nothing and stay there. Throwing
			// puts the batch back instead.
			throw new RuntimeException( 'Could not resolve a dimension id for a ' . $type->name . ' value.' );
		}

		$this->memo[ $key ] = $id;

		return $id;
	}

	/**
	 * Find the id for a value without creating one.
	 *
	 * Used by the cardinality cap - creating first and checking the cap
	 * afterwards would bound the rollups while leaving the dimension table to
	 * grow with traffic, which is the same problem moved one table along - and
	 * by the reports, where an unvisited path should be a 404 rather than a
	 * page of zeroes.
	 *
	 * @param DimensionType $type  Dimension type.
	 * @param string        $value Value.
	 */
	public function getId( DimensionType $type, string $value ): ?int {
		$value = self::normalize( $value );
		$hash  = self::valueHash( $value );
		$key   = $type->value . ':' . $hash;

		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$this->memo[ $key ] = $this->lookup( $type, $hash );

		return $this->memo[ $key ];
	}

	/**
	 * The stored value for an id.
	 *
	 * @param int $id Dimension id.
	 */
	public function value( int $id ): ?string {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM `$table` WHERE id = %d", $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $value ? null : (string) $value;
	}

	/**
	 * Look a hash up in the database.
	 *
	 * @param DimensionType $type Dimension type.
	 * @param string        $hash Value hash.
	 */
	private function lookup( DimensionType $type, string $hash ): ?int {
		global $wpdb;

		$table = Tables::name( Tables::DIMENSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `$table` WHERE type = %d AND valueHash = %s", $type->value, $hash )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $id ? null : (int) $id;
	}

	/**
	 * Truncate a value to what the column holds.
	 *
	 * @param string $value Value.
	 */
	public static function normalize( string $value ): string {
		return mb_substr( $value, 0, self::MAX_VALUE_LENGTH );
	}

	/**
	 * The lookup hash for a value.
	 *
	 * A short fixed-width hash rather than an index on a 500-character column:
	 * the unique key stays small, and it behaves the same on every database.
	 *
	 * @param string $value Value.
	 */
	public static function valueHash( string $value ): string {
		$algorithm = in_array( 'xxh3', hash_algos(), true ) ? 'xxh3' : 'crc32c';
		$hash      = hash( $algorithm, $value );

		return str_pad( substr( $hash, 0, 16 ), 16, '0', STR_PAD_LEFT );
	}

	/**
	 * Forget the per-request memo. For long-running CLI work.
	 */
	public function flush(): void {
		$this->memo = [];
	}
}
