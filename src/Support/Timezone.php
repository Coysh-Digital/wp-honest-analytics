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
	 * A stored `Y-m-d` date as a timestamp that renders as that same date.
	 *
	 * Every date in this plugin is stored as a local calendar date, and every
	 * date on a screen is printed with `wp_date()`, which renders in the
	 * site's timezone. Bridging the two with `strtotime()` is wrong in a way
	 * that is invisible from London and obvious from New York: WordPress fixes
	 * PHP's default zone to UTC, so `strtotime( '2026-08-23' )` is midnight
	 * *UTC*, and `wp_date()` turns that back into 20:00 on the 22nd. Every
	 * label, axis tick, tooltip and range note one day early, all year.
	 *
	 * Anchored at midday rather than midnight so that no offset, and no DST
	 * transition in either direction, can carry the result into a neighbouring
	 * day.
	 *
	 * @param string            $date     A `Y-m-d` date.
	 * @param DateTimeZone|null $timezone Override, for tests.
	 *
	 * @return int|false The timestamp, or false when the date cannot be read.
	 */
	public static function middayOn( string $date, ?DateTimeZone $timezone = null ): int|false {
		$parsed = DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			trim( $date ),
			$timezone ?? self::site()
		);

		if ( false === $parsed ) {
			return false;
		}

		return $parsed->setTime( 12, 0 )->getTimestamp();
	}

	/**
	 * A timestamp formatted in the site's timezone.
	 *
	 * `wp_date()` is documented as returning false, and does so when the site's
	 * timezone cannot be constructed - a corrupt `timezone_string` option, or a
	 * PHP build whose tzdata does not carry the named zone. Every call site was
	 * handing the result straight to `esc_html()`, where false prints as an
	 * empty cell: a date silently disappears from a report and nothing says
	 * why. Falling back to UTC prints a date that is at worst a few hours out,
	 * which is a better answer than a blank one.
	 *
	 * @param string   $format    A date() format string.
	 * @param int|null $timestamp Unix timestamp, defaulting to now.
	 */
	public static function format( string $format, ?int $timestamp = null ): string {
		$formatted = wp_date( $format, $timestamp );

		if ( false !== $formatted ) {
			return $formatted;
		}

		return gmdate( $format, $timestamp ?? time() );
	}

	/**
	 * A date written the way this site writes dates.
	 *
	 * For dates in a sentence - "support runs until", "data through", "from X
	 * to Y". Settings > General has a date format on it and somebody chose the
	 * one there; a plugin printing 3 March 2026 on a site that writes
	 * 2026-03-03 everywhere else is ignoring an answer it was given.
	 *
	 * Not for dates in a table cell or on a chart axis. Those use a fixed
	 * compact format on purpose, because `l, jS F Y` is a legitimate setting
	 * and a column sized for `3 Mar` cannot hold "Tuesday, 3rd March 2026".
	 * The distinction is width, not correctness.
	 *
	 * @param int|null $timestamp Unix timestamp, defaulting to now.
	 */
	public static function date( ?int $timestamp = null ): string {
		$format = (string) get_option( 'date_format' );

		return self::format( '' === $format ? 'j F Y' : $format, $timestamp );
	}

	/**
	 * A date and a time, both the way this site writes them.
	 *
	 * @param int|null $timestamp Unix timestamp, defaulting to now.
	 */
	public static function dateTime( ?int $timestamp = null ): string {
		$date = (string) get_option( 'date_format' );
		$time = (string) get_option( 'time_format' );

		return self::format(
			( '' === $date ? 'j F Y' : $date ) . ', ' . ( '' === $time ? 'H:i' : $time ),
			$timestamp
		);
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
