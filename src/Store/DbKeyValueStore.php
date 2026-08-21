<?php
/**
 * Database-backed store.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Store;

use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small table standing in for an object cache.
 *
 * Most WordPress sites have no persistent object cache, and the parts of this
 * pipeline that need cross-request state are not optional: without them hybrid
 * mode cannot deduplicate, the rate limit cannot count, and the drain throttle
 * cannot throttle. One indexed row per nonce is a real cost and it is stated
 * plainly in the settings; silently failing open would be worse.
 *
 * Not transients: transients live in the options table, autoload rules make
 * them awkward, and cleaning them up is somebody else's cron. A table we own
 * can be indexed, swept, and dropped on uninstall.
 */
final class DbKeyValueStore implements KeyValueStoreInterface {

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		global $wpdb;

		$table = Tables::name( Tables::KV );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT n, v, expires FROM `$table` WHERE k = %s",
				$this->key( $key )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		$expires = (int) ( $row['expires'] ?? 0 );

		if ( $expires > 0 && $expires <= time() ) {
			$this->delete( $key );

			return null;
		}

		if ( null !== $row['v'] && '' !== $row['v'] ) {
			$decoded = json_decode( (string) $row['v'], true );

			return null === $decoded && 'null' !== $row['v'] ? $row['v'] : $decoded;
		}

		return (int) $row['n'];
	}

	/**
	 * Write a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		global $wpdb;

		$table   = Tables::name( Tables::KV );
		$expires = $ttl > 0 ? time() + $ttl : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$table` (k, n, v, expires) VALUES (%s, %d, %s, %d)
				ON DUPLICATE KEY UPDATE n = VALUES(n), v = VALUES(v), expires = VALUES(expires)",
				$this->key( $key ),
				is_int( $value ) ? $value : 0,
				is_int( $value ) ? null : (string) wp_json_encode( $value ),
				$expires
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $result;
	}

	/**
	 * Write only when absent - or when what is there has expired.
	 *
	 * A plain INSERT IGNORE would refuse forever once a key had been written
	 * once, so the expired case is handled by deleting first. The delete is
	 * conditional on the expiry, so a live key is never disturbed.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 */
	public function add( string $key, mixed $value, int $ttl = 0 ): bool {
		global $wpdb;

		$table   = Tables::name( Tables::KV );
		$now     = time();
		$expires = $ttl > 0 ? $now + $ttl : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `$table` WHERE k = %s AND expires > 0 AND expires <= %d",
				$this->key( $key ),
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `$table` (k, n, v, expires) VALUES (%s, %d, %s, %d)",
				$this->key( $key ),
				is_int( $value ) ? $value : 0,
				is_int( $value ) ? null : (string) wp_json_encode( $value ),
				$expires
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $result;
	}

	/**
	 * Delete a key.
	 *
	 * The return value is the whole point for the nonce registry: a conditional
	 * delete that reports whether it removed anything is an atomic claim, which
	 * is stronger than the read-then-delete an object cache can offer.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): bool {
		global $wpdb;

		$table = Tables::name( Tables::KV );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `$table` WHERE k = %s AND (expires = 0 OR expires > %d)",
				$this->key( $key ),
				time()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $result;
	}

	/**
	 * Increment a counter in one statement.
	 *
	 * LAST_INSERT_ID(expr) returns the incremented value on the same round
	 * trip, so a rate limit costs one query rather than a read and a write with
	 * a race between them.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Seconds.
	 */
	public function increment( string $key, int $ttl = 60 ): int {
		global $wpdb;

		$table   = Tables::name( Tables::KV );
		$now     = time();
		$expires = $ttl > 0 ? $now + $ttl : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$table` (k, n, v, expires) VALUES (%s, 1, NULL, %d)
				ON DUPLICATE KEY UPDATE
					n = IF(expires > 0 AND expires <= %d, LAST_INSERT_ID(1), LAST_INSERT_ID(n + 1)),
					expires = IF(expires > 0 AND expires <= %d, VALUES(expires), expires)",
				$this->key( $key ),
				$expires,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$value = (int) $wpdb->insert_id;

		return $value > 0 ? $value : 1;
	}

	/**
	 * Always true: a table is as persistent as it gets.
	 */
	public function isPersistent(): bool {
		return true;
	}

	/**
	 * Delete everything that has expired.
	 *
	 * @return int Rows removed.
	 */
	public function sweep(): int {
		global $wpdb;

		$table = Tables::name( Tables::KV );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `$table` WHERE expires > 0 AND expires <= %d", time() )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Scope a key to this site.
	 *
	 * The table is already blog-prefixed, so this only guards against two
	 * concerns colliding on the same string.
	 *
	 * @param string $key Key.
	 */
	private function key( string $key ): string {
		return substr( $key, 0, 191 );
	}
}
