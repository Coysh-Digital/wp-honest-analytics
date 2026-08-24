<?php
/**
 * Counter upserts.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

use HonestAnalytics\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add to a counter, creating the row if this is the first time today.
 *
 * Every rollup write in the plugin goes through here, so the one statement that
 * has to be right is in one place.
 */
final class Upsert {

	/** The largest value a decimal(14,2) column can hold. */
	public const DECIMAL_14_2_MAX = '999999999999.99';

	/** The largest value a decimal(12,4) column can hold. */
	public const DECIMAL_12_4_MAX = '99999999.9999';

	/**
	 * Increment counters on a keyed row.
	 *
	 * @param string                  $table      Unprefixed table name.
	 * @param array<string,mixed>     $keys       Columns forming the unique key.
	 * @param array<string,int|float> $counters Columns to add to.
	 * @param array<string,mixed>     $extra      Columns to set on insert only.
	 * @param string[]                $fillIfNull Columns from $extra to fill when still null.
	 * @param array<string,string>    $caps       Column => maximum, for decimals.
	 */
	public static function counters(
		string $table,
		array $keys,
		array $counters,
		array $extra = [],
		array $fillIfNull = [],
		array $caps = []
	): void {
		global $wpdb;

		$name    = Tables::name( $table );
		$columns = array_merge( $keys, $counters, $extra );

		if ( [] === $columns ) {
			return;
		}

		$names        = [];
		$placeholders = [];
		$values       = [];

		foreach ( $columns as $column => $value ) {
			$names[]        = '`' . str_replace( '`', '', (string) $column ) . '`';
			$placeholders[] = self::placeholder( $value );
			$values[]       = $value;
		}

		$updates = [];

		foreach ( array_keys( $counters ) as $column ) {
			$quoted = '`' . str_replace( '`', '', (string) $column ) . '`';
			$sum    = "$quoted + VALUES($quoted)";

			if ( isset( $caps[ $column ] ) ) {
				// Saturate rather than overflow. An out-of-range error inside the
				// drain transaction fails identically on every retry, which
				// quarantines the batch and loses every metric in it to protect
				// one pinned number. A pinned number on one row is a curiosity.
				$cap = self::decimal( (string) $caps[ $column ] );
				$sum = "GREATEST(-$cap, LEAST($cap, $sum))";
			}

			$updates[] = "$quoted = $sum";
		}

		foreach ( $fillIfNull as $column ) {
			if ( ! array_key_exists( $column, $extra ) ) {
				continue;
			}

			$quoted = '`' . str_replace( '`', '', (string) $column ) . '`';

			// Describes the row rather than counting it: the first resolved
			// answer wins and is never overwritten. This is what stops a row
			// created by a beacon - which knows no post ID - from staying
			// invisible to the content reports forever.
			$updates[] = "$quoted = COALESCE($quoted, VALUES($quoted))";
		}

		if ( [] === $updates ) {
			$updates[] = '`' . str_replace( '`', '', (string) array_key_first( $keys ) ) . '` = ' .
				'`' . str_replace( '`', '', (string) array_key_first( $keys ) ) . '`';
		}

		$sql = 'INSERT INTO `' . $name . '` (' . implode( ', ', $names ) . ') VALUES (' .
			implode( ', ', $placeholders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );

		// Throws on failure. Every caller runs inside a transaction whose catch
		// rolls back and retries; a counter that quietly failed to add would
		// leave the batch marked committed and the views gone for good.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		Db::query( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The prepare placeholder for a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function placeholder( mixed $value ): string {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return '%d';
		}

		if ( is_float( $value ) ) {
			return '%f';
		}

		return '%s';
	}

	/**
	 * A decimal literal, validated so it can be interpolated.
	 *
	 * @param string $value Numeric string.
	 */
	private static function decimal( string $value ): string {
		return 1 === preg_match( '/^\d+(\.\d+)?$/', $value ) ? $value : '0';
	}
}
