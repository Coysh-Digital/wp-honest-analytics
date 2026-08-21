<?php
/**
 * Cross-process locking.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A MySQL advisory lock.
 *
 * Two drains running at once would not just duplicate work, they would close
 * the same session twice and double every session-scoped figure it carries.
 * GET_LOCK is used rather than an object-cache lock because it is released
 * automatically when the connection dies, which is exactly the case a lock
 * around a long-running batch job has to survive. It also works on every host,
 * with or without a persistent object cache.
 */
final class Lock {

	private string $name;
	private bool $held = false;

	/**
	 * @param string $name Lock name, scoped to this site by the caller.
	 */
	public function __construct( string $name ) {
		global $wpdb;

		$prefix     = isset( $wpdb ) ? $wpdb->prefix : 'wp_';
		$this->name = substr( 'ha_' . $prefix . $name, 0, 64 );
	}

	/**
	 * Try to take the lock.
	 *
	 * @param int $timeout Seconds to wait. 0 means "fail immediately".
	 */
	public function acquire( int $timeout = 0 ): bool {
		global $wpdb;

		if ( $this->held ) {
			return true;
		}

		if ( ! isset( $wpdb ) ) {
			$this->held = true;

			return true;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name, $timeout ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->held = '1' === (string) $result;

		return $this->held;
	}

	/**
	 * Release the lock, if held.
	 */
	public function release(): void {
		global $wpdb;

		if ( ! $this->held || ! isset( $wpdb ) ) {
			$this->held = false;

			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->held = false;
	}

	/**
	 * Whether this instance currently holds the lock.
	 */
	public function isHeld(): bool {
		return $this->held;
	}
}
