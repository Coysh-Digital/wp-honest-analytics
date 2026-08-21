<?php
/**
 * Site timezone access.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every rollup row is keyed by a local date, so every date calculation in the
 * plugin has to agree about which local that is. This is the one place that
 * decides.
 */
final class Timezone {

	/**
	 * The site's timezone.
	 *
	 * `wp_timezone()` can return a bare offset (`+05:30`) when the site is
	 * configured with a UTC offset rather than a named zone; every consumer
	 * here treats it as opaque, so that is fine.
	 */
	public static function site(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'UTC' );
	}

	/**
	 * A timestamp as a DateTimeImmutable in the site's timezone.
	 *
	 * @param int               $timestamp Unix timestamp.
	 * @param DateTimeZone|null $timezone  Override, for tests.
	 */
	public static function at( int $timestamp, ?DateTimeZone $timezone = null ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone ?? self::site() );
	}

	/**
	 * The local date (Y-m-d) and hour (0-23) for a timestamp.
	 *
	 * @param int               $timestamp Unix timestamp.
	 * @param DateTimeZone|null $timezone  Override, for tests.
	 *
	 * @return array{0:string,1:int}
	 */
	public static function dateAndHour( int $timestamp, ?DateTimeZone $timezone = null ): array {
		$local = self::at( $timestamp, $timezone );

		return [ $local->format( 'Y-m-d' ), (int) $local->format( 'G' ) ];
	}

	/**
	 * Today's local date, as Y-m-d.
	 *
	 * @param int|null          $now      Timestamp, defaulting to now.
	 * @param DateTimeZone|null $timezone Override, for tests.
	 */
	public static function today( ?int $now = null, ?DateTimeZone $timezone = null ): string {
		return self::at( $now ?? time(), $timezone )->format( 'Y-m-d' );
	}
}
