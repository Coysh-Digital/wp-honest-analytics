<?php
/**
 * Admin assets.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stylesheet and the two scripts, loaded only where they are needed.
 *
 * Chart.js is 70 kilobytes and is enqueued only on screens that actually draw
 * something. Eight of the fifteen screens chart nothing, and neither the
 * dashboard widget nor the post editor panel touch it at all - both draw their
 * sparklines as server-rendered SVG.
 */
final class Assets {

	/**
	 * Load the admin styles, and optionally the charting code.
	 *
	 * @param bool $charts Whether this screen draws charts.
	 */
	public static function admin( bool $charts = false ): void {
		wp_enqueue_style(
			'honest-analytics-admin',
			HONEST_ANALYTICS_URL . 'assets/admin/css/admin.css',
			[],
			HONEST_ANALYTICS_VERSION
		);

		wp_enqueue_script(
			'honest-analytics-admin',
			HONEST_ANALYTICS_URL . 'assets/admin/js/admin.js',
			[],
			HONEST_ANALYTICS_VERSION,
			true
		);

		if ( ! $charts ) {
			return;
		}

		wp_enqueue_script(
			'honest-analytics-chartjs',
			HONEST_ANALYTICS_URL . 'assets/admin/js/vendor/chart.umd.js',
			[],
			'4.5.1',
			true
		);

		wp_enqueue_script(
			'honest-analytics-charts',
			HONEST_ANALYTICS_URL . 'assets/admin/js/charts.js',
			[ 'honest-analytics-chartjs' ],
			HONEST_ANALYTICS_VERSION,
			true
		);
	}

	/**
	 * Load the polling script for the real-time screen and the live widget.
	 *
	 * @param string $selector A CSS selector for the container to update.
	 */
	public static function realtime( string $selector = '[data-ha-realtime]' ): void {
		wp_enqueue_script(
			'honest-analytics-realtime',
			HONEST_ANALYTICS_URL . 'assets/admin/js/realtime.js',
			[],
			HONEST_ANALYTICS_VERSION,
			true
		);

		wp_localize_script(
			'honest-analytics-realtime',
			'honestAnalyticsRealtime',
			[
				'endpoint' => rest_url( 'honest-analytics/v1/realtime' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'selector' => $selector,
				'interval' => 15000,
				'strings'  => [
					'justNow'     => __( 'just now', 'honest-analytics' ),
					/* translators: %d: a number of seconds. */
					'secondsAgo'  => __( '%ds ago', 'honest-analytics' ),
					/* translators: %d: a number of minutes. */
					'minutesAgo'  => __( '%dm ago', 'honest-analytics' ),
					/* translators: %s: how long ago the figures were refreshed, already formatted. */
					'updated'     => __( 'Updated %s', 'honest-analytics' ),
					'justArrived' => __( 'just arrived', 'honest-analytics' ),
					'nobody'      => __( 'Nobody is on the site right now.', 'honest-analytics' ),
					/* translators: %d: the number of rows beyond those listed. */
					'more'        => __( '%d more not shown', 'honest-analytics' ),
				],
			]
		);
	}

	/**
	 * Load the small stylesheet the widgets and the editor panel share.
	 */
	public static function widget(): void {
		wp_enqueue_style(
			'honest-analytics-widgets',
			HONEST_ANALYTICS_URL . 'assets/admin/css/widgets.css',
			[],
			HONEST_ANALYTICS_VERSION
		);
	}
}
