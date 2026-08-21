<?php
/**
 * The Settings screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\MaintenanceHandler;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Integrations\CacheDetector;
use HonestAnalytics\Plugin;
use HonestAnalytics\Scheduling\Health;
use HonestAnalytics\Settings\SettingsRepository;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Write\SpoolStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every setting, with its consequence stated beside it.
 *
 * The defaults are the privacy-preserving option in every group, and the ones
 * that change that posture say so in as many words rather than leaving somebody
 * to work it out from a settings name.
 */
final class SettingsScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-settings';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Settings', 'honest-analytics' );
	}

	/**
	 * Only people who can change what is collected.
	 */
	public function capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * Prose reads badly at report width.
	 */
	public function maxWidth(): int {
		return 900;
	}

	/**
	 * Nothing here is time-bounded.
	 */
	public function hasRange(): bool {
		return false;
	}

	/**
	 * No charts.
	 */
	public function usesCharts(): bool {
		return false;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();
		$health   = new Health();

		View::render(
			'admin/settings',
			[
				'params'       => $this->params(),
				'settings'     => $settings,
				'isPro'        => Edition::isPro(),
				'health'       => $health,
				'caches'       => CacheDetector::detected(),
				'delaysJs'     => CacheDetector::delaysJavaScript(),
				'spool'        => new SpoolStatus( $settings ),
				'objectCache'  => StoreFactory::usingObjectCache(),
				'sessionStore' => $plugin->sessions()->name(),
				'writer'       => $plugin->writer()->name(),
				'geo'          => $plugin->geo()->databaseInfo(),
				'presets'      => DateRange::presets(),
				'overrides'    => static fn ( string $key ): ?string => SettingsRepository::overrideSource( $key ),
				'maintenance'  => MaintenanceHandler::takeNotice(),
				'quarantined'  => $this->quarantinedBatches( $health ),
			]
		);
	}

	/**
	 * How many batches the last drain gave up on.
	 *
	 * Read from the record the drain leaves behind rather than by counting
	 * files, because the answer only decides whether one button is shown and a
	 * settings screen should not go looking through a directory to draw itself.
	 *
	 * @param Health $health The health check, already built by the caller.
	 */
	private function quarantinedBatches( Health $health ): int {
		$last = $health->lastDrain();

		return null !== $last ? (int) ( $last['quarantinedBatches'] ?? 0 ) : 0;
	}
}
