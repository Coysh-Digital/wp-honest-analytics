<?php
/**
 * Number and duration formatting.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reports and the charts have to round identically, or the tooltip and the
 * table beneath it disagree in front of the reader. Both sides format here (the
 * JavaScript mirrors these rules exactly).
 */
final class Format {

	/**
	 * A count, grouped for the site's locale.
	 *
	 * @param int|float $value Value.
	 */
	public static function count( int|float $value ): string {
		return number_format_i18n( (float) $value, 0 );
	}

	/**
	 * A compact count: 1.2k, 3.4M.
	 *
	 * @param int|float $value Value.
	 */
	public static function compact( int|float $value ): string {
		$value = (float) $value;

		if ( $value >= 1000000 ) {
			$decimals = $value >= 10000000 ? 0 : 1;

			/* translators: %s: a number of millions, already formatted. The suffix on a compacted figure - "1.2M" in English. Keep it short: this goes in a table cell and on a chart axis. */
			return sprintf( __( '%sM', 'honest-analytics' ), self::trimZero( number_format_i18n( $value / 1000000, $decimals ) ) );
		}

		if ( $value >= 1000 ) {
			$decimals = $value >= 10000 ? 0 : 1;

			/* translators: %s: a number of thousands, already formatted. The suffix on a compacted figure - "1.2k" in English. Keep it short: this goes in a table cell and on a chart axis. */
			return sprintf( __( '%sk', 'honest-analytics' ), self::trimZero( number_format_i18n( $value / 1000, $decimals ) ) );
		}

		return number_format_i18n( $value, 0 );
	}

	/**
	 * A duration in milliseconds, as "2m 14s".
	 *
	 * @param int $milliseconds Milliseconds.
	 */
	public static function duration( int $milliseconds ): string {
		$seconds = (int) round( $milliseconds / 1000 );

		if ( $seconds >= 3600 ) {
			$hours   = intdiv( $seconds, 3600 );
			$minutes = intdiv( $seconds % 3600, 60 );

			/* translators: 1: hours, 2: minutes. */
			return sprintf( __( '%1$dh %2$dm', 'honest-analytics' ), $hours, $minutes );
		}

		if ( $seconds >= 60 ) {
			$minutes = intdiv( $seconds, 60 );
			$rest    = $seconds % 60;

			/* translators: 1: minutes, 2: seconds. */
			return sprintf( __( '%1$dm %2$02ds', 'honest-analytics' ), $minutes, $rest );
		}

		/* translators: %d: seconds. */
		return sprintf( __( '%ds', 'honest-analytics' ), $seconds );
	}

	/**
	 * A percentage to one decimal place.
	 *
	 * @param float $value Percentage, 0-100.
	 * @param int   $decimals Decimal places.
	 */
	public static function percent( float $value, int $decimals = 1 ): string {
		/* translators: %s: a number, already formatted. Several locales put a space before the sign. */
		return sprintf( __( '%s%%', 'honest-analytics' ), number_format_i18n( $value, $decimals ) );
	}

	/**
	 * A signed delta, as "+8.4%" or "-2.1%".
	 *
	 * @param float $value Percentage difference.
	 */
	public static function delta( float $value ): string {
		$sign = $value > 0 ? '+' : ( $value < 0 ? "\u{2212}" : '' );

		return $sign . number_format_i18n( abs( $value ), 1 ) . '%';
	}

	/**
	 * The largest value in one column of a rows array.
	 *
	 * The denominator a ranked table divides by to size its bars. Every call
	 * site used to guard `max()` on the rows being non-empty, which is the
	 * wrong thing to check: `array_column()` returns an empty array whenever
	 * the column is absent from every row - a renamed or misspelled key - and
	 * `max()` throws a ValueError on that, taking the whole screen with it.
	 * The emptiness has to be tested after the column is extracted, not before.
	 *
	 * Returns 0 for nothing to measure. Every consumer either floors at 1 or
	 * checks for a positive value before dividing.
	 *
	 * @param array<int, array<string, mixed>> $rows   Rows to measure.
	 * @param string                           $column Column holding the value.
	 */
	public static function largest( array $rows, string $column ): int {
		$values = array_column( $rows, $column );

		return [] === $values ? 0 : (int) max( $values );
	}

	/**
	 * Drop a trailing ".0" from a formatted number.
	 *
	 * @param string $value Formatted number.
	 */
	private static function trimZero( string $value ): string {
		$decimal = '.';

		if ( function_exists( 'wp_locale_get_details' ) ) {
			global $wp_locale;

			if ( isset( $wp_locale ) && is_object( $wp_locale ) && isset( $wp_locale->number_format['decimal_point'] ) ) {
				$decimal = (string) $wp_locale->number_format['decimal_point'];
			}
		}

		if ( str_ends_with( $value, $decimal . '0' ) ) {
			return substr( $value, 0, -strlen( $decimal . '0' ) );
		}

		return $value;
	}
}
