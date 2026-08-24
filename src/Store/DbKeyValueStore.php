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
			// Not delete(): that refuses to touch an expired row, by design,
			// so the row would survive here and be found again on every read
			// until the sweep. The sweep takes it instead.
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

		// A read first, because the answer is almost always no. The throttles
		// ask this on every front-end request and every admin page, and the
		// key is live for all but one of them; paying two writes to learn that
		// is two row locks and two redo-log entries per page view for nothing.
		// The insert below is still what decides a race - this only spares the
		// requests that were never in one.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT expires FROM `$table` WHERE k = %s", $this->key( $key ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null !== $current && ( 0 === (int) $current || (int) $current > $now ) ) {
			return false;
		}

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

	/** Rows removed per statement while sweeping. */
	private const SWEEP_CHUNK = 5000;

	/**
	 * Delete what has expired, a chunk at a time.
	 *
	 * Every nonce, every rate-limit window and every throttle is a row here,
	 * and on a site without an object cache that is a row per page view and a
	 * row per visitor per minute, all dead within the hour. Left to a single
	 * nightly DELETE that is a million-row statement holding locks on the one
	 * table the capture path writes to on every request. Chunks are short,
	 * and the drain calls this with a ceiling every few minutes so the
	 * nightly run has little left to do.
	 *
	 * @param int $maxRows The most to remove in this call.
	 *
	 * @return int Rows removed.
	 */
	public function sweep( int $maxRows = PHP_INT_MAX ): int {
		global $wpdb;

		$table   = Tables::name( Tables::KV );
		$now     = time();
		$removed = 0;

		while ( $removed < $maxRows ) {
			$chunk = min( self::SWEEP_CHUNK, $maxRows - $removed );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$deleted = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$table` WHERE expires > 0 AND expires <= %d LIMIT %d", $now, $chunk )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$removed += $deleted;

			if ( $deleted < $chunk ) {
				break;
			}
		}

		return $removed;
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
