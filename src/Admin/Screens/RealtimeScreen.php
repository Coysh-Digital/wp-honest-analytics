<?php
/**
 * The Real-time screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Assets;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who is here now.
 *
 * No date range and no export, because nothing on this screen is historical.
 */
final class RealtimeScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-realtime';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Real-time', 'honest-analytics' );
	}

	/**
	 * No range picker.
	 */
	public function hasRange(): bool {
		return false;
	}

	/**
	 * No charts: this is a list that refreshes.
	 */
	public function usesCharts(): bool {
		return false;
	}

	/**
	 * Enqueue the polling script as well as the styles.
	 */
	public function enqueue(): void {
		parent::enqueue();

		Assets::realtime();
	}

	/**
	 * Render.
	 */
	public function render(): void {
		View::render(
			'admin/realtime',
			[
				'snapshot'      => Plugin::instance()->realtime()->snapshot( $this->siteId() ),
				'sessionWindow' => Plugin::instance()->settings()->sessionWindow,
			]
		);
	}
}
