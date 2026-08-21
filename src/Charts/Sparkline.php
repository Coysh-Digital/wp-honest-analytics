<?php
/**
 * Server-rendered sparklines.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Charts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A tiny line, drawn in PHP.
 *
 * The dashboard widget and the post editor panel use this rather than the
 * charting library, so those two places add no JavaScript at all. Nothing about
 * them can be broken by a script optimiser, a content-security policy, or an
 * asset that failed to publish - which matters more for a panel inside somebody
 * else's editing screen than a smooth curve does.
 */
final class Sparkline {

	/**
	 * The SVG path for a series.
	 *
	 * @param int[] $values Values.
	 * @param float $width  Width in user units.
	 * @param float $height Height in user units.
	 */
	public static function path( array $values, float $width = 120, float $height = 28 ): string {
		$values = array_values( array_map( 'intval', $values ) );
		$count  = count( $values );

		if ( 0 === $count ) {
			return '';
		}

		$max  = max( 1, max( $values ) );
		$step = $count > 1 ? $width / ( $count - 1 ) : 0.0;
		$path = '';

		foreach ( $values as $index => $value ) {
			// A single point sits in the middle rather than hard against the
			// left edge, where it reads as a truncated chart.
			$x = $count > 1 ? $index * $step : $width / 2;
			$y = $height - ( $value / $max * ( $height - 2 ) ) - 1;

			$path .= ( 0 === $index ? 'M' : 'L' ) . self::number( $x ) . ' ' . self::number( $y );
		}

		return $path;
	}

	/**
	 * Format a coordinate compactly.
	 *
	 * @param float $value Coordinate.
	 */
	private static function number( float $value ): string {
		$formatted = number_format( $value, 2, '.', '' );
		$trimmed   = rtrim( rtrim( $formatted, '0' ), '.' );

		return '' === $trimmed ? '0' : $trimmed;
	}
}
