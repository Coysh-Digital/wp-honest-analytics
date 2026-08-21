<?php
/**
 * Short-lived key/value storage contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The small amount of state the pipeline needs between requests.
 *
 * Nonces, rate-limit counters, the drain throttle and the salt memo. On a site
 * with Redis or Memcached this is the object cache; on the majority of
 * WordPress installs, which have no persistent cache at all, it is a table.
 * Both have to work, because "sessions vanish between requests" is not a
 * degraded mode, it is a broken one.
 */
interface KeyValueStoreInterface {

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 *
	 * @return mixed Null when absent or expired.
	 */
	public function get( string $key ): mixed;

	/**
	 * Write a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds to live. 0 means no expiry.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool;

	/**
	 * Write a value only if the key is absent.
	 *
	 * The atomic primitive the throttle and the nonce registry rely on.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds to live.
	 *
	 * @return bool True when this caller created the key.
	 */
	public function add( string $key, mixed $value, int $ttl = 0 ): bool;

	/**
	 * Delete a key.
	 *
	 * @param string $key Key.
	 *
	 * @return bool True when this caller removed an existing key.
	 */
	public function delete( string $key ): bool;

	/**
	 * Increment a counter, creating it at 1 when absent.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Seconds to live, applied on creation.
	 *
	 * @return int The value after incrementing.
	 */
	public function increment( string $key, int $ttl = 60 ): int;

	/**
	 * Whether this store survives between requests.
	 */
	public function isPersistent(): bool;
}
