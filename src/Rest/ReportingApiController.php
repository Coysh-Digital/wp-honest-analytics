<?php
/**
 * The read-only reporting API endpoints.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Stats\StatsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an external reporting tool pulls: a verify handshake and a period report.
 *
 * Read-only and aggregate-only. The report is assembled from the same
 * StatsService the admin dashboard uses, so it never exposes anything the site
 * owner cannot already see, and - true to the plugin - it carries no visitor
 * identifiers, only counts.
 */
final class ReportingApiController {

	/** A bound on the breakdown lists returned, so a report stays small. */
	private const LIMIT = 10;

	private StatsService $stats;

	/**
	 * @param StatsService $stats Stats service.
	 */
	public function __construct( StatsService $stats ) {
		$this->stats = $stats;
	}

	/**
	 * Build one from the container.
	 */
	public static function make(): self {
		return new self( Plugin::instance()->stats() );
	}

	/**
	 * The verify handshake, so a client can confirm the connection.
	 */
	public function verify(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'ok'                => true,
				'connector'         => 'honest-analytics',
				'version'           => defined( 'HONEST_ANALYTICS_VERSION' ) ? HONEST_ANALYTICS_VERSION : '',
				'wordpress_version' => get_bloginfo( 'version' ),
			]
		);
	}

	/**
	 * The period report: normalised totals, a daily trend and the top
	 * breakdowns, in a shape a reporting tool can map to the usual metrics.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function report( \WP_REST_Request $request ): \WP_REST_Response {
		$siteId = get_current_blog_id();
		$range  = DateRange::custom(
			(string) $request->get_param( 'from' ),
			(string) $request->get_param( 'to' )
		);

		$totals = $this->stats->totals( $siteId, $range );
		$trend  = $this->stats->trend( $siteId, $range );

		return new \WP_REST_Response(
			[
				'provider'   => 'Honest Analytics',
				'metrics'    => [
					'visitors'       => (int) ( $totals['uniques'] ?? 0 ),
					'pageviews'      => (int) ( $totals['views'] ?? 0 ),
					'visits'         => (int) ( $totals['sessions'] ?? 0 ),
					'bounce_rate'    => round( (float) ( $totals['bounceRate'] ?? 0 ), 2 ),
					// Visit duration is reported in seconds.
					'visit_duration' => (int) round( ( (int) ( $totals['avgDurationMs'] ?? 0 ) ) / 1000 ),
				],
				'timeseries' => $this->trendSeries( $trend ),
				'top_pages'  => $this->topPages( $siteId, $range ),
				'sources'    => $this->sources( $siteId, $range ),
				'devices'    => $this->devices( $siteId, $range ),
			]
		);
	}

	/**
	 * Turn the trend's parallel label/unique arrays into dated points.
	 *
	 * @param array<string,mixed> $trend Trend payload from StatsService.
	 * @return array<int,array{date:string,value:int}>
	 */
	private function trendSeries( array $trend ): array {
		$labels  = is_array( $trend['labels'] ?? null ) ? $trend['labels'] : [];
		$uniques = is_array( $trend['uniques'] ?? null ) ? $trend['uniques'] : [];

		$series = [];
		foreach ( $labels as $i => $label ) {
			$series[] = [
				'date'  => (string) $label,
				'value' => (int) ( $uniques[ $i ] ?? 0 ),
			];
		}

		return $series;
	}

	/**
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @return array<int,array{label:string,visitors:int,pageviews:int}>
	 */
	private function topPages( int $siteId, DateRange $range ): array {
		return array_map(
			static fn ( array $row ): array => [
				'label'     => (string) ( $row['path'] ?? '' ),
				// Honest Analytics counts page views, not per-page uniques.
				'visitors'  => (int) ( $row['views'] ?? 0 ),
				'pageviews' => (int) ( $row['views'] ?? 0 ),
			],
			$this->stats->topPages( $siteId, $range, self::LIMIT )
		);
	}

	/**
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @return array<int,array{label:string,visitors:int}>
	 */
	private function sources( int $siteId, DateRange $range ): array {
		return array_map(
			static function ( array $row ): array {
				$host = (string) ( $row['host'] ?? '' );

				return [
					'label'    => '' !== $host ? $host : 'Direct',
					'visitors' => (int) ( $row['sessions'] ?? 0 ),
				];
			},
			array_slice( $this->stats->sources( $siteId, $range, self::LIMIT ), 0, self::LIMIT )
		);
	}

	/**
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @return array<int,array{label:string,visitors:int}>
	 */
	private function devices( int $siteId, DateRange $range ): array {
		return array_map(
			static fn ( array $row ): array => [
				'label'    => (string) ( $row['label'] ?? 'Unknown' ),
				'visitors' => (int) ( $row['sessions'] ?? 0 ),
			],
			$this->stats->devices( $siteId, $range, 'deviceType' )
		);
	}
}
