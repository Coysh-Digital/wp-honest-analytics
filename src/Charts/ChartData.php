<?php
/**
 * Chart payloads.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Charts;

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
	 * The traffic trend.
	 *
	 * @param array{labels:string[],views:int[],uniques:int[],hourly:bool} $trend Trend data.
	 *
	 * @return array<string,mixed>
	 */
	public static function trend( array $trend ): array {
		$labels = self::axisLabels( $trend['labels'], $trend['hourly'] );

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

		$payload = self::line(
			$labels['axis'],
			$datasets,
			[ 'maxTicks' => $trend['hourly'] ? 8 : 6 ]
		);

		$payload['full']       = $labels['full'];
		$payload['hasUniques'] = $hasUniques;

		return $payload;
	}

	/**
	 * Short and long forms of the same axis labels.
	 *
	 * Built together because they were previously derived in two places that
	 * could - and did - disagree about what day it was.
	 *
	 * @param string[] $labels Raw labels.
	 * @param bool     $hourly Whether the axis is hours.
	 *
	 * @return array{axis:string[],full:string[]}
	 */
	public static function axisLabels( array $labels, bool $hourly ): array {
		if ( $hourly ) {
			return [
				'axis' => $labels,
				'full' => $labels,
			];
		}

		$axis = [];
		$full = [];

		foreach ( $labels as $label ) {
			$timestamp = strtotime( (string) $label );

			if ( false === $timestamp ) {
				$axis[] = (string) $label;
				$full[] = (string) $label;

				continue;
			}

			$axis[] = wp_date( 'j M', $timestamp );
			$full[] = wp_date( 'j M Y', $timestamp );
		}

		return [
			'axis' => $axis,
			'full' => $full,
		];
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
				'label' => (string) ( $dataset['label'] ?? '' ),
				'data'  => array_values( (array) ( $dataset['data'] ?? [] ) ),
				'token' => (string) ( $dataset['token'] ?? self::seriesToken( $index ) ),
				'fill'  => (bool) ( $dataset['fill'] ?? false ),
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
