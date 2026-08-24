<?php
/**
 * Chart payloads.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Charts;

use HonestAnalytics\Stats\Granularity;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract between PHP and the charting code.
 *
 * PHP emits labels, numbers and *series tokens*. It never emits a colour. The
 * JavaScript resolves each token against a CSS custom property on the figure,
 * which means the palette lives in one stylesheet, follows the admin colour
 * scheme, and can be changed without touching a single query.
 *
 * Four series plus a neutral "Other" is a hard limit rather than a style
 * preference: beyond four, the distance between colours drops below what
 * remains distinguishable with any of the common colour-vision deficiencies.
 * Long tails fold rather than the palette widening.
 */
final class ChartData {

	/** Distinct coloured series before the tail folds. */
	public const MAX_SERIES = 4;

	/** The reserved neutral, always used for the folded tail. */
	public const OTHER_TOKEN = 'series-5';

	/**
	 * A line chart.
	 *
	 * @param string[]                       $labels   X-axis labels.
	 * @param array<int,array<string,mixed>> $datasets Series.
	 * @param array<string,mixed>            $options  Extra options.
	 *
	 * @return array<string,mixed>
	 */
	public static function line( array $labels, array $datasets, array $options = [] ): array {
		return self::payload( 'line', $labels, $datasets, $options );
	}

	/**
	 * A stacked area chart.
	 *
	 * @param string[]                       $labels   X-axis labels.
	 * @param array<int,array<string,mixed>> $datasets Series.
	 * @param array<string,mixed>            $options  Extra options.
	 *
	 * @return array<string,mixed>
	 */
	public static function stackedArea( array $labels, array $datasets, array $options = [] ): array {
		return self::payload( 'stackedArea', $labels, $datasets, $options );
	}

	/**
	 * A doughnut chart.
	 *
	 * Shares are computed here rather than in the browser, so the tooltip and
	 * the table beside it cannot round differently.
	 *
	 * @param string[]            $labels  Slice labels.
	 * @param int[]               $values  Slice values.
	 * @param array<string,mixed> $options Extra options.
	 *
	 * @return array<string,mixed>
	 */
	public static function doughnut( array $labels, array $values, array $options = [] ): array {
		$payload = self::payload(
			'doughnut',
			$labels,
			[
				[
					'label' => $options['label'] ?? __( 'Sessions', 'honest-analytics' ),
					'data'  => array_values( $values ),
				],
			],
			$options
		);

		$payload['shares'] = self::shares( $values );

		return $payload;
	}

	/**
	 * The traffic trend, optionally overlaid with a comparison period.
	 *
	 * The comparison series is aligned by position, not by calendar date: both
	 * ranges have the same bucket count by construction ({@see
	 * \HonestAnalytics\Stats\DateRange::previous()} and {@see
	 * \HonestAnalytics\Stats\DateRange::sameLastYear()} both preserve the
	 * span), so point *N* of one sits directly under point *N* of the other
	 * even though the two fall on different real dates. The axis shown is
	 * always the primary range's.
	 *
	 * @param array{labels:string[],views:int[],uniques:int[],hourly:bool,granularity?:string}      $trend           Trend data.
	 * @param array{labels:string[],views:int[],uniques:int[],hourly:bool,granularity?:string}|null $comparisonTrend The comparison period's trend, at the same grain.
	 * @param string|null                                                                           $comparisonLabel What to call the comparison series, e.g. "Previous period".
	 *
	 * @return array<string,mixed>
	 */
	public static function trend( array $trend, ?array $comparisonTrend = null, ?string $comparisonLabel = null ): array {
		$granularity = Granularity::fromParam( $trend['granularity'] ?? null );
		$labels      = self::axisLabels( $trend['labels'], $trend['hourly'], $granularity );

		$datasets = [
			[
				'label' => __( 'Pageviews', 'honest-analytics' ),
				'data'  => array_values( $trend['views'] ),
				'token' => 'views',
				'fill'  => true,
			],
		];

		$hasUniques = [] !== $trend['uniques'] && max( $trend['uniques'] ) > 0;

		if ( $hasUniques ) {
			$datasets[] = [
				'label' => __( 'Unique visitors', 'honest-analytics' ),
				'data'  => array_values( $trend['uniques'] ),
				'token' => 'uniques',
				'fill'  => false,
			];
		}

		$hasComparison = null !== $comparisonTrend;

		if ( $hasComparison ) {
			$points = count( $trend['views'] );

			$datasets[] = [
				'label'  => $comparisonLabel ?? __( 'Comparison period', 'honest-analytics' ),
				'data'   => self::alignedTo( array_values( $comparisonTrend['views'] ), $points ),
				'token'  => 'compare',
				'fill'   => false,
				'dashed' => true,
			];
		}

		$payload = self::line(
			$labels['axis'],
			$datasets,
			[ 'maxTicks' => self::maxTicks( $trend['hourly'], $granularity ) ]
		);

		$payload['full']       = $labels['full'];
		$payload['hasUniques'] = $hasUniques;

		if ( $hasComparison ) {
			$compareLabels          = self::axisLabels( $comparisonTrend['labels'], $comparisonTrend['hourly'], $granularity );
			$payload['compareFull'] = self::alignedTo( $compareLabels['full'], count( $labels['full'] ), '' );
		}

		return $payload;
	}

	/**
	 * Pad or trim a series to an exact length.
	 *
	 * The comparison range is built to have the same bucket count as the
	 * primary one, so this is a safety net for the one thing that could break
	 * that guarantee - a leap day landing differently in the two spans - not
	 * the normal path.
	 *
	 * @param array<int,mixed> $values Values.
	 * @param int              $length Target length.
	 * @param mixed            $pad    Fill value.
	 *
	 * @return array<int,mixed>
	 */
	private static function alignedTo( array $values, int $length, mixed $pad = 0 ): array {
		$values = array_slice( array_values( $values ), 0, $length );

		while ( count( $values ) < $length ) {
			$values[] = $pad;
		}

		return $values;
	}

	/**
	 * How many x-axis ticks a trend chart shows before Chart.js starts
	 * skipping labels.
	 *
	 * Enough buckets to read as a trend, few enough that the labels never
	 * overlap - a judgement call tuned per grain, not a derived number.
	 *
	 * @param bool        $hourly      Whether the axis is hours.
	 * @param Granularity $granularity The bucket size.
	 */
	private static function maxTicks( bool $hourly, Granularity $granularity ): int {
		if ( $hourly ) {
			return 8;
		}

		return match ( $granularity ) {
			Granularity::Day   => 6,
			Granularity::Week  => 12,
			Granularity::Month => 12,
			Granularity::Year  => 10,
		};
	}

	/**
	 * Short and long forms of the same axis labels.
	 *
	 * Built together because they were previously derived in two places that
	 * could - and did - disagree about what day it was.
	 *
	 * The year is added to the short form only when the label set itself spans
	 * more than one calendar year - derived from the labels rather than passed
	 * in, so this stays stateless and every existing call site gets the fix
	 * for free the moment it upgrades to pass a real `$granularity`.
	 *
	 * @param string[]    $labels      Raw labels - dates, or bucket keys at a
	 *                                 coarser grain (see {@see Granularity::bucketKeyFor()}).
	 * @param bool        $hourly      Whether the axis is hours.
	 * @param Granularity $granularity The bucket size the labels are keyed at.
	 *
	 * @return array{axis:string[],full:string[]}
	 */
	public static function axisLabels( array $labels, bool $hourly, Granularity $granularity = Granularity::Day ): array {
		if ( $hourly ) {
			return [
				'axis' => $labels,
				'full' => $labels,
			];
		}

		$timestamps = array_map(
			static fn ( $label ): int|false => self::bucketTimestamp( (string) $label, $granularity ),
			$labels
		);

		$years = array_unique(
			array_map(
				static fn ( $timestamp ) => false === $timestamp ? null : (int) gmdate( 'Y', $timestamp ),
				array_filter( $timestamps, static fn ( $timestamp ): bool => false !== $timestamp )
			)
		);

		$spansYears = count( $years ) > 1;

		$axis = [];
		$full = [];

		foreach ( $labels as $index => $label ) {
			$timestamp = $timestamps[ $index ];

			if ( false === $timestamp ) {
				$axis[] = (string) $label;
				$full[] = (string) $label;

				continue;
			}

			[ $axisFormat, $fullFormat ] = self::labelFormats( $granularity, $spansYears );

			$axis[]   = Timezone::format( $axisFormat, $timestamp );
			$fullDate = Timezone::format( $fullFormat, $timestamp );

			$full[] = Granularity::Week === $granularity
				? sprintf(
					/* translators: %s: the date a week starts on. */
					__( 'Week of %s', 'honest-analytics' ),
					$fullDate
				)
				: $fullDate;
		}

		return [
			'axis' => $axis,
			'full' => $full,
		];
	}

	/**
	 * The date format pair for a grain, short (axis) and long (full/tooltip).
	 *
	 * The full form for Week is a plain date format here - {@see axisLabels()}
	 * wraps it in the translatable "Week of %s" itself, rather than putting
	 * English words inside a format string a translator would have to leave
	 * untouched to keep working.
	 *
	 * @param Granularity $granularity The bucket size.
	 * @param bool        $spansYears  Whether the label set spans more than one calendar year.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function labelFormats( Granularity $granularity, bool $spansYears ): array {
		return match ( $granularity ) {
			Granularity::Day   => [ $spansYears ? 'j M Y' : 'j M', 'j M Y' ],
			Granularity::Week  => [ $spansYears ? 'j M Y' : 'j M', 'j M Y' ],
			Granularity::Month => [ $spansYears ? 'M Y' : 'M', 'F Y' ],
			Granularity::Year  => [ 'Y', 'Y' ],
		};
	}

	/**
	 * Resolve one bucket label to a real timestamp, for formatting.
	 *
	 * @param string      $label       A `Y-m-d` date, or a coarser bucket key.
	 * @param Granularity $granularity The grain the label is keyed at.
	 */
	private static function bucketTimestamp( string $label, Granularity $granularity ): int|false {
		if ( Granularity::Week === $granularity ) {
			if ( 1 !== preg_match( '/^(\d{4})W(\d{2})$/', $label, $matches ) ) {
				return false;
			}

			// Midday in the site's zone, for the same reason every other
			// branch here uses it: the label is rendered with wp_date().
			$date = ( new \DateTimeImmutable( 'now', Timezone::site() ) )
				->setISODate( (int) $matches[1], (int) $matches[2] )
				->setTime( 12, 0 );

			return $date->getTimestamp();
		}

		if ( Granularity::Month === $granularity ) {
			return Timezone::middayOn( $label . '-01' );
		}

		if ( Granularity::Year === $granularity ) {
			return Timezone::middayOn( $label . '-01-01' );
		}

		return Timezone::middayOn( $label );
	}

	/**
	 * Turn rows into zero-filled series.
	 *
	 * @param array<int,array<string,mixed>> $rows       Rows.
	 * @param string[]                       $dates      Every date in the range.
	 * @param string                         $keyField   Field naming the series.
	 * @param string                         $valueField Field holding the number.
	 * @param string                         $dateField  Field holding the date.
	 *
	 * @return array<string,int[]>
	 */
	public static function pivot( array $rows, array $dates, string $keyField, string $valueField, string $dateField = 'date' ): array {
		$positions = array_flip( $dates );
		$series    = [];

		foreach ( $rows as $row ) {
			$key  = (string) ( $row[ $keyField ] ?? '' );
			$date = (string) ( $row[ $dateField ] ?? '' );

			// A row outside the window is dropped rather than folded onto an
			// edge, where it would show as a spike that never happened.
			if ( ! isset( $positions[ $date ] ) ) {
				continue;
			}

			if ( ! isset( $series[ $key ] ) ) {
				$series[ $key ] = array_fill( 0, count( $dates ), 0 );
			}

			$series[ $key ][ $positions[ $date ] ] += (int) ( $row[ $valueField ] ?? 0 );
		}

		return $series;
	}

	/**
	 * Keep the biggest series and fold the rest into one.
	 *
	 * The tie-break on key is deliberate: without it, two series with equal
	 * totals could swap places between two renders of the same report, and the
	 * legend would appear to reorder itself at random.
	 *
	 * @param array<string,int[]> $series     Series.
	 * @param int                 $keep       How many to keep.
	 * @param string|null         $otherLabel Label for the folded tail.
	 *
	 * @return array<string,int[]>
	 */
	public static function foldToTop( array $series, int $keep = self::MAX_SERIES, ?string $otherLabel = null ): array {
		if ( count( $series ) <= $keep ) {
			return $series;
		}

		$totals = [];

		foreach ( $series as $key => $values ) {
			$totals[ $key ] = array_sum( $values );
		}

		uksort(
			$series,
			static fn ( string $a, string $b ): int => [ $totals[ $b ], $a ] <=> [ $totals[ $a ], $b ]
		);

		$kept  = array_slice( $series, 0, $keep, true );
		$tail  = array_slice( $series, $keep, null, true );
		$other = [];

		foreach ( $tail as $values ) {
			foreach ( $values as $index => $value ) {
				$other[ $index ] = ( $other[ $index ] ?? 0 ) + $value;
			}
		}

		if ( [] !== $other ) {
			ksort( $other );

			$kept[ $otherLabel ?? __( 'Other', 'honest-analytics' ) ] = array_values( $other );
		}

		return $kept;
	}

	/**
	 * Turn series into datasets with tokens assigned.
	 *
	 * @param array<string,int[]> $series     Series.
	 * @param string|null         $otherLabel Label treated as the neutral tail.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function datasets( array $series, ?string $otherLabel = null ): array {
		$otherLabel = $otherLabel ?? __( 'Other', 'honest-analytics' );
		$datasets   = [];
		$index      = 0;

		foreach ( $series as $label => $values ) {
			$datasets[] = [
				'label' => (string) $label,
				'data'  => array_values( $values ),
				// "Other" always takes the reserved neutral, wherever it lands
				// in the ordering.
				'token' => (string) $label === $otherLabel ? self::OTHER_TOKEN : self::seriesToken( $index ),
				'fill'  => false,
			];

			++$index;
		}

		return $datasets;
	}

	/**
	 * The token for a series index.
	 *
	 * Wraps rather than running out: a repeated colour is a legible chart with
	 * an ambiguity, an uncoloured series is a broken one.
	 *
	 * @param int $index Series index.
	 */
	public static function seriesToken( int $index ): string {
		return 'series-' . ( ( $index % self::MAX_SERIES ) + 1 );
	}

	/**
	 * Percentages that sum to a hundred.
	 *
	 * @param int[] $values Values.
	 *
	 * @return float[]
	 */
	public static function shares( array $values ): array {
		$total = array_sum( $values );

		if ( $total <= 0 ) {
			return array_fill( 0, count( $values ), 0.0 );
		}

		return array_map( static fn ( $value ): float => round( $value / $total * 100, 1 ), array_values( $values ) );
	}

	/**
	 * Assemble a payload.
	 *
	 * @param string                         $type     Chart type.
	 * @param string[]                       $labels   Labels.
	 * @param array<int,array<string,mixed>> $datasets Series.
	 * @param array<string,mixed>            $options  Options.
	 *
	 * @return array<string,mixed>
	 */
	private static function payload( string $type, array $labels, array $datasets, array $options = [] ): array {
		$prepared = [];

		foreach ( array_values( $datasets ) as $index => $dataset ) {
			$prepared[] = [
				'label'  => (string) ( $dataset['label'] ?? '' ),
				'data'   => array_values( (array) ( $dataset['data'] ?? [] ) ),
				'token'  => (string) ( $dataset['token'] ?? self::seriesToken( $index ) ),
				'fill'   => (bool) ( $dataset['fill'] ?? false ),
				// Dashed rather than a new hue: a comparison series reads as
				// "the same metric, a different period", not a different metric.
				'dashed' => (bool) ( $dataset['dashed'] ?? false ),
			];
		}

		unset( $options['label'] );

		return [
			'type'     => $type,
			'labels'   => array_values( $labels ),
			'datasets' => $prepared,
			// So the canvas and the table beneath it group digits identically.
			'locale'   => str_replace( '_', '-', get_locale() ),
			'options'  => $options,
		];
	}
}
