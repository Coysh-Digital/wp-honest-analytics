<?php
/**
 * The dashboard overview widget.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Widgets;

use HonestAnalytics\Admin\Assets;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Charts\Sparkline;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Headline figures on the WordPress dashboard.
 *
 * Server-rendered, with an SVG sparkline and no JavaScript. Adding a
 * seventy-kilobyte charting library to somebody's dashboard to draw a line
 * three hundred pixels wide is not a trade worth making.
 *
 * The range and the metrics are stored per user, because two people looking at
 * the same dashboard usually want different things from it.
 */
final class OverviewWidget {

	public const ID        = 'honest_analytics_overview';
	public const USER_META = 'honest_analytics_overview_widget';

	/**
	 * Attach the hook.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'add' ] );
	}

	/**
	 * Add the widget.
	 */
	public static function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::ID,
			__( 'Analytics', 'honest-analytics' ),
			[ self::class, 'render' ],
			[ self::class, 'control' ]
		);
	}

	/**
	 * The metrics somebody can choose from.
	 *
	 * One list, read by the settings form, the body and the validation.
	 *
	 * @return array<string,string>
	 */
	public static function metrics(): array {
		return [
			'views'              => __( 'Pageviews', 'honest-analytics' ),
			'uniques'            => __( 'Visitors', 'honest-analytics' ),
			'sessions'           => __( 'Sessions', 'honest-analytics' ),
			'bounceRate'         => __( 'Bounce rate', 'honest-analytics' ),
			'avgViewsPerSession' => __( 'Pages / session', 'honest-analytics' ),
			'avgDurationMs'      => __( 'Avg session', 'honest-analytics' ),
			'avgDwellMs'         => __( 'Avg time on page', 'honest-analytics' ),
		];
	}

	/**
	 * This user's preferences.
	 *
	 * @return array{range:string,metrics:string[]}
	 */
	public static function preferences(): array {
		$stored = get_user_meta( get_current_user_id(), self::USER_META, true );
		$stored = is_array( $stored ) ? $stored : [];

		$range = isset( $stored['range'] ) && isset( DateRange::presets()[ $stored['range'] ] )
			? (string) $stored['range']
			: '7d';

		$metrics = isset( $stored['metrics'] ) && is_array( $stored['metrics'] )
			? array_values( array_intersect( $stored['metrics'], array_keys( self::metrics() ) ) )
			: [];

		return [
			'range'   => $range,
			'metrics' => [] !== $metrics ? $metrics : [ 'views', 'uniques', 'bounceRate' ],
		];
	}

	/**
	 * Render the widget.
	 */
	public static function render(): void {
		Assets::widget();

		$plugin      = Plugin::instance();
		$preferences = self::preferences();
		$siteId      = get_current_blog_id();
		$range       = DateRange::fromPreset(
			$preferences['range'],
			null,
			null,
			static fn (): ?string => $plugin->stats()->firstRecordedDate( $siteId )
		);

		$totals = $plugin->stats()->totals( $siteId, $range );
		$trend  = $plugin->stats()->trend( $siteId, $range );

		View::render(
			'widgets/overview',
			[
				'range'     => $range,
				'totals'    => $totals,
				'metrics'   => $preferences['metrics'],
				'labels'    => self::metrics(),
				'sparkline' => Sparkline::path( $trend['views'], 300, 40 ),
				'link'      => add_query_arg( 'range', $range->param, menu_page_url( 'honest-analytics', false ) ),
				'accuracy'  => $plugin->stats()->uniquesAccuracy(),
				'beacon'    => $plugin->settings()->usesBeacon(),
			]
		);
	}

	/**
	 * Render and handle the widget's settings form.
	 */
	public static function control(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the dashboard widget form.
		if ( isset( $_POST['honest_analytics_overview'] ) && is_array( $_POST['honest_analytics_overview'] ) ) {
			// Each member is passed through sanitize_key() immediately below;
			// the array itself cannot be sanitized in one call.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$posted  = wp_unslash( $_POST['honest_analytics_overview'] );
			$range   = isset( $posted['range'] ) ? sanitize_key( (string) $posted['range'] ) : '7d';
			$metrics = isset( $posted['metrics'] ) && is_array( $posted['metrics'] )
				? array_map( 'sanitize_key', $posted['metrics'] )
				: [];

			update_user_meta(
				get_current_user_id(),
				self::USER_META,
				[
					'range'   => isset( DateRange::presets()[ $range ] ) ? $range : '7d',
					'metrics' => array_values( array_intersect( $metrics, array_keys( self::metrics() ) ) ),
				]
			);
		}

		View::render(
			'widgets/overview-controls',
			[
				'preferences' => self::preferences(),
				'presets'     => DateRange::presets(),
				'metrics'     => self::metrics(),
			]
		);
	}
}
