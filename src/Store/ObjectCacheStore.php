<?php
/**
 * Object-cache backed store.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The persistent object cache, when there is one.
 *
 * The group is deliberately not registered as global, so the object cache
 * prefixes keys per blog and multisite scoping comes for free.
 */
final class ObjectCacheStore implements KeyValueStoreInterface {

	public const GROUP = 'honest-analytics';

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		if ( false === $value && ! $found ) {
			return null;
		}

		return $value;
	}

	/**
	 * Write a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		return (bool) wp_cache_set( $key, $value, self::GROUP, max( 0, $ttl ) );
	}

	/**
	 * Write only when absent.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds.
	 */
	public function add( string $key, mixed $value, int $ttl = 0 ): bool {
		return (bool) wp_cache_add( $key, $value, self::GROUP, max( 0, $ttl ) );
	}

	/**
	 * Delete a key.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): bool {
		return (bool) wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Increment a counter.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Seconds.
	 */
	public function increment( string $key, int $ttl = 60 ): int {
		if ( $this->add( $key, 1, $ttl ) ) {
			return 1;
		}

		$value = wp_cache_incr( $key, 1, self::GROUP );

		if ( false === $value ) {
			// Some drop-ins refuse incr on a value they did not create as an
			// integer. Fall back to a read-modify-write; the count runs low
			// under concurrency, which is the right way round for something
			// that bounds abuse rather than granting access.
			$current = (int) $this->get( $key );
			$value   = $current + 1;
			$this->set( $key, $value, $ttl );
		}

		return (int) $value;
	}

	/**
	 * Whether the cache survives between requests.
	 */
	public function isPersistent(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}
}
