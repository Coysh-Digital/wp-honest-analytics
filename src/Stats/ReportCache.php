<?php
/**
 * Report caching.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers the answer to a report query, but only when it cannot change.
 *
 * The dividing line is `includesToday()`. A period that has finished is
 * arithmetic over rows nothing will write to again, so it can be held for an
 * hour and served from memory. A period that includes today is still being
 * added to by every drain, and a reader refreshing a screen to see whether the
 * morning's post is doing anything deserves the truth rather than a cached
 * fifteen-minute-old copy.
 *
 * Two layers, deliberately:
 *
 * 1. A per-request memo, which always applies. One screen render asks for the
 *    same totals from the KPI row, the chart and the sparkline; those three
 *    should agree with each other and cost one query, whatever the range.
 * 2. The object cache, which only applies to finished periods, and only when
 *    there is one. On a site with no persistent cache `wp_cache_*` is an array
 *    that dies with the request - which is exactly layer one, so nothing is
 *    lost and nothing is stale.
 *
 * The group matches the one StatsService uses for its own memoisation, so
 * flushing here clears both.
 */
final class ReportCache {

	/** Shared with StatsService, so one flush clears every report. */
	public const GROUP = 'honest-analytics-reports';

	/** An hour. A finished period cannot change, so this is only a memory bound. */
	public const TTL = 3600;

	/**
	 * The per-request memo.
	 *
	 * @var array<string,mixed>
	 */
	private static array $memo = [];

	/**
	 * Return a remembered value, computing it if it is not held.
	 *
	 * @param string               $name    Query name.
	 * @param int                  $siteId  Site ID.
	 * @param DateRange            $range   Period.
	 * @param callable             $compute Produces the value.
	 * @param array<string,scalar> $extra   Anything else that changes the answer.
	 */
	public static function remember( string $name, int $siteId, DateRange $range, callable $compute, array $extra = [] ): mixed {
		$key = self::key( $name, $siteId, $range, $extra );

		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		if ( ! self::isCacheable( $range ) ) {
			self::$memo[ $key ] = $compute();

			return self::$memo[ $key ];
		}

		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		if ( ! $found ) {
			$value = $compute();

			wp_cache_set( $key, $value, self::GROUP, self::TTL );
		}

		self::$memo[ $key ] = $value;

		return $value;
	}

	/**
	 * The key for one query.
	 *
	 * @param string               $name   Query name.
	 * @param int                  $siteId Site ID.
	 * @param DateRange            $range  Period.
	 * @param array<string,scalar> $extra  Anything else that changes the answer.
	 */
	public static function key( string $name, int $siteId, DateRange $range, array $extra = [] ): string {
		ksort( $extra );

		$parts = [ $name, (string) $siteId, $range->from, $range->to ];

		foreach ( $extra as $label => $value ) {
			$parts[] = $label . '=' . ( is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value );
		}

		return implode( ':', $parts );
	}

	/**
	 * Whether a period is finished and therefore safe to hold.
	 *
	 * @param DateRange $range Period.
	 */
	public static function isCacheable( DateRange $range ): bool {
		return ! $range->includesToday() && function_exists( 'wp_cache_get' );
	}

	/**
	 * Drop one entry.
	 *
	 * @param string               $name   Query name.
	 * @param int                  $siteId Site ID.
	 * @param DateRange            $range  Period.
	 * @param array<string,scalar> $extra  Anything else that changes the answer.
	 */
	public static function forget( string $name, int $siteId, DateRange $range, array $extra = [] ): void {
		$key = self::key( $name, $siteId, $range, $extra );

		unset( self::$memo[ $key ] );

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, self::GROUP );
		}
	}

	/**
	 * Drop everything.
	 *
	 * Group flushing is not something every object cache can do, and the ones
	 * that cannot must not be handed `wp_cache_flush()` instead: emptying the
	 * whole site's cache to refresh a report is a cure worse than the disease.
	 * The per-request memo always clears; the return value says honestly whether
	 * the persistent layer did too.
	 */
	public static function flush(): bool {
		self::$memo = [];

		if ( ! function_exists( 'wp_cache_flush_group' ) ) {
			return false;
		}

		if ( function_exists( 'wp_cache_supports' ) && ! wp_cache_supports( 'flush_group' ) ) {
			return false;
		}

		return (bool) wp_cache_flush_group( self::GROUP );
	}
}
