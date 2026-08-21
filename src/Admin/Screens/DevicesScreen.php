<?php
/**
 * The Devices screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Devices\DeviceType;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What people browse on.
 */
final class DevicesScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-devices';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Devices', 'honest-analytics' );
	}

	/**
	 * What this screen exports.
	 */
	public function exportKind(): string {
		return 'devices';
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$stats  = Plugin::instance()->stats();
		$params = $this->params();
		$siteId = $this->siteId();
		$range  = $params->range;

		$types = [];

		foreach ( $stats->devices( $siteId, $range, 'deviceType' ) as $row ) {
			$types[] = [
				'label'    => DeviceType::fromStored( (int) $row['label'] )->label(),
				'sessions' => (int) $row['sessions'],
			];
		}

		View::render(
			'admin/devices',
			[
				'params'    => $params,
				'types'     => $types,
				// A doughnut only where the set is small and closed. Browsers
				// and operating systems have a long tail that a doughnut turns
				// into a fringe of unreadable slivers.
				'typeChart' => ChartData::doughnut(
					array_column( $types, 'label' ),
					array_column( $types, 'sessions' ),
					[ 'label' => __( 'Sessions', 'honest-analytics' ) ]
				),
				'browsers'  => $stats->devices( $siteId, $range, 'browser' ),
				'systems'   => $stats->devices( $siteId, $range, 'os' ),
			]
		);
	}
}
