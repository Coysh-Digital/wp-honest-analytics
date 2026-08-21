<?php
/**
 * The day-and-hour grid.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Charts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When people visit, as a table rather than a canvas.
 *
 * A hundred and sixty-eight labelled cells with row and column headers is a
 * table. Rendering it as one gives keyboard navigation, header associations for
 * screen readers, text zoom, printing and copy-paste for free - all of which a
 * canvas would have to reimplement badly.
 */
final class Heatmap {

	/** Shades above "nothing happened". */
	public const BUCKETS = 6;

	public const DAYS  = 7;
	public const HOURS = 24;

	/**
	 * Build the grid.
	 *
	 * @param array<int,array<string,mixed>> $rows         Rows of date, hour and views.
	 * @param int                            $weekStartDay 1 for Monday, 0 for Sunday.
	 *
	 * @return array{cells:array<int,array<int,array{views:int,bucket:int}>>,dayIndexes:int[],max:int,total:int,covered:bool,peak:array{day:int,hour:int,views:int}}
	 */
	public static function grid( array $rows, int $weekStartDay = 1 ): array {
		$totals = array_fill( 0, self::DAYS, array_fill( 0, self::HOURS, 0 ) );
		$max    = 0;
		$total  = 0;
		$peak   = [
			'day'   => 0,
			'hour'  => 0,
			'views' => 0,
		];

		foreach ( $rows as $row ) {
			$hour = (int) ( $row['hour'] ?? -1 );

			// A compacted daily row carries hour -1 and has nothing to place.
			if ( $hour < 0 || $hour >= self::HOURS ) {
				continue;
			}

			$timestamp = strtotime( (string) ( $row['date'] ?? '' ) );

			if ( false === $timestamp ) {
				continue;
			}

			$weekday = (int) gmdate( 'w', $timestamp );
			$views   = (int) ( $row['views'] ?? 0 );

			$totals[ $weekday ][ $hour ] += $views;
			$total                       += $views;

			if ( $totals[ $weekday ][ $hour ] > $max ) {
				$max  = $totals[ $weekday ][ $hour ];
				$peak = [
					'day'   => $weekday,
					'hour'  => $hour,
					'views' => $max,
				];
			}
		}

		// Rows are rotated rather than re-keyed, so the caller labels them from
		// the same order it iterates.
		$dayIndexes = [];

		for ( $i = 0; $i < self::DAYS; $i++ ) {
			$dayIndexes[] = ( $weekStartDay + $i ) % self::DAYS;
		}

		$cells = [];

		foreach ( $dayIndexes as $weekday ) {
			$row = [];

			for ( $hour = 0; $hour < self::HOURS; $hour++ ) {
				$views = $totals[ $weekday ][ $hour ];

				$row[] = [
					'views'  => $views,
					'bucket' => self::bucket( $views, $max ),
				];
			}

			$cells[] = $row;
		}

		return [
			'cells'      => $cells,
			'dayIndexes' => $dayIndexes,
			'max'        => $max,
			'total'      => $total,
			'covered'    => $total > 0,
			'peak'       => $peak,
		];
	}

	/**
	 * Which shade a cell gets.
	 *
	 * Square-rooted, so one dominant hour does not flatten every other cell into
	 * the palest shade - the pattern is the entire point of a heatmap, and a
	 * linear scale hides it whenever a site has a single busy hour.
	 *
	 * @param int $value This cell's views.
	 * @param int $max   The busiest cell's views.
	 */
	public static function bucket( int $value, int $max ): int {
		if ( $value <= 0 || $max <= 0 ) {
			return 0;
		}

		$share = sqrt( $value / $max );

		return max( 1, (int) round( $share * self::BUCKETS ) );
	}
}
