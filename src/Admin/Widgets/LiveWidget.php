<?php
/**
 * The dashboard real-time widget.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Widgets;

use HonestAnalytics\Admin\Assets;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who is on the site, on the WordPress dashboard.
 *
 * Rendered server-side first so it is right before any JavaScript runs, then
 * refreshed by the same polling script the Real-time screen uses.
 */
final class LiveWidget {

	public const ID = 'honest_analytics_live';

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

		wp_add_dashboard_widget( self::ID, __( 'Right now', 'honest-analytics' ), [ self::class, 'render' ] );
	}

	/**
	 * Render the widget.
	 */
	public static function render(): void {
		Assets::widget();
		Assets::realtime( '[data-ha-realtime]' );

		$plugin = Plugin::instance();

		View::render(
			'widgets/live',
			[
				'snapshot'      => $plugin->realtime()->snapshot( get_current_blog_id(), null, 8 ),
				'sessionWindow' => $plugin->settings()->sessionWindow,
				'link'          => menu_page_url( 'honest-analytics-realtime', false ),
			]
		);
	}
}
