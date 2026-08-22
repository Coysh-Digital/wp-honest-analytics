<?php
/**
 * Chart bucket sizes.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How wide one point on a trend chart is.
 *
 * Independent of the date range: a range is *what* is being reported on, this
 * is *how coarsely*. Day-level rollups are kept for the whole retention
 * window, so every grain here is a re-bucketing of rows that already exist -
 * nothing here changes what is stored, only how it is grouped for a chart.
 *
 * Nothing here throws, for the same reason {@see DateRange} does not: this
 * arrives from a query string, and an unreadable one falls back to a day
 * rather than an error page.
 */
enum Granularity: string {

	case Day   = 'day';
	case Week  = 'week';
	case Month = 'month';
	case Year  = 'year';

	/**
	 * Build from a URL parameter. An unrecognised value quietly becomes a day.
	 *
	 * @param string|null $value Parameter value.
	 */
	public static function fromParam( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Day;
	}

	/**
	 * A human label, for the toolbar control.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Day   => __( 'Day', 'honest-analytics' ),
			self::Week  => __( 'Week', 'honest-analytics' ),
			self::Month => __( 'Month', 'honest-analytics' ),
			self::Year  => __( 'Year', 'honest-analytics' ),
		};
	}

	/**
	 * The grains that make sense for a given range.
	 *
	 * Day is always offered. The others need enough of the range to produce
	 * more than a couple of buckets, or the control would offer a choice that
	 * immediately clamps back down - the thresholds below are a judgement
	 * call, not a derived fact, and are deliberately generous rather than
	 * exact. A single-day (hourly) range offers only Day: the hourly axis
	 * already answers the question a coarser grain would ask.
	 *
	 * @param DateRange $range Range.
	 *
	 * @return self[]
	 */
	public static function availableFor( DateRange $range ): array {
		if ( $range->isHourly() ) {
			return [ self::Day ];
		}

		$days      = $range->days();
		$available = [ self::Day ];

		if ( $days >= 14 ) {
			$available[] = self::Week;
		}

		if ( $days >= 60 ) {
			$available[] = self::Month;
		}

		if ( $days >= 365 ) {
			$available[] = self::Year;
		}

		return $available;
	}

	/**
	 * The closest available grain to what was asked for.
	 *
	 * Degrades to the coarsest option still available rather than jumping
	 * straight to Day: Month requested on a twenty-day range becomes Week, not
	 * a chart nobody asked to see bucketed by day.
	 *
	 * @param Granularity $requested What was asked for.
	 * @param DateRange   $range     Range.
	 */
	public static function clamp( Granularity $requested, DateRange $range ): self {
		$available = self::availableFor( $range );

		if ( in_array( $requested, $available, true ) ) {
			return $requested;
		}

		return $available[ count( $available ) - 1 ];
	}

	/**
	 * The bucket a date falls into, at this grain.
	 *
	 * Week uses the ISO year-week rather than a plain year: 31 December can
	 * fall in week 1 of the following ISO year, and a plain `Y` would put that
	 * day's bucket key out of order with the days around it.
	 *
	 * @param string $date `Y-m-d`.
	 */
	public function bucketKeyFor( string $date ): string {
		$timestamp = strtotime( $date . ' 00:00:00' );

		if ( false === $timestamp ) {
			return $date;
		}

		return match ( $this ) {
			self::Day   => $date,
			self::Week  => gmdate( 'o\WW', $timestamp ),
			self::Month => gmdate( 'Y-m', $timestamp ),
			self::Year  => gmdate( 'Y', $timestamp ),
		};
	}

	/**
	 * The ordered, zero-filled list of bucket keys spanning a range.
	 *
	 * The granular sibling of {@see DateRange::dates()}: every bucket a range
	 * touches, in order, with no gaps - so a chart can zero-fill a month with
	 * no traffic instead of skipping it.
	 *
	 * @param DateRange $range Range.
	 *
	 * @return string[]
	 */
	public function bucketsFor( DateRange $range ): array {
		$buckets = [];

		foreach ( $range->dates() as $date ) {
			$key = $this->bucketKeyFor( $date );

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = true;
			}
		}

		return array_keys( $buckets );
	}
}
