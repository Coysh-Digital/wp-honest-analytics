<?php
/**
 * Store selection.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chooses between the object cache and the table, once per request.
 *
 * The detection is deliberately conservative. `wp_using_ext_object_cache()`
 * returns true for APCu-backed drop-ins, which are per-server: on a
 * multi-server host they would silently split nonces and sessions across
 * machines, so the health check names that case and the `db` override exists
 * for it.
 */
final class StoreFactory {

	/**
	 * The chosen store.
	 *
	 * @var KeyValueStoreInterface|null
	 */
	private static ?KeyValueStoreInterface $store = null;

	/**
	 * The store for nonces, rate limits and throttles.
	 */
	public static function keyValue(): KeyValueStoreInterface {
		if ( null !== self::$store ) {
			return self::$store;
		}

		self::$store = self::build();

		return self::$store;
	}

	/**
	 * Whether the object cache is being used.
	 */
	public static function usingObjectCache(): bool {
		return self::keyValue() instanceof ObjectCacheStore;
	}

	/**
	 * Replace the store. For tests.
	 *
	 * @param KeyValueStoreInterface|null $store Store, or null to re-detect.
	 */
	public static function set( ?KeyValueStoreInterface $store ): void {
		self::$store = $store;
	}

	/**
	 * Decide which store to use.
	 */
	private static function build(): KeyValueStoreInterface {
		$mode = defined( 'HONEST_ANALYTICS_STORE' ) ? (string) constant( 'HONEST_ANALYTICS_STORE' ) : 'auto';

		/**
		 * Filters which key/value store the pipeline uses.
		 *
		 * @param string $mode One of 'auto', 'cache', 'db'.
		 */
		$mode = (string) apply_filters( 'honest_analytics_store', $mode );

		if ( 'cache' === $mode ) {
			return new ObjectCacheStore();
		}

		if ( 'db' === $mode ) {
			return new DbKeyValueStore();
		}

		$cache = new ObjectCacheStore();

		return $cache->isPersistent() ? $cache : new DbKeyValueStore();
	}
}
