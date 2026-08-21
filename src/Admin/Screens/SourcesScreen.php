<?php
/**
 * The Sources screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where visits came from.
 */
final class SourcesScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-sources';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Sources', 'honest-analytics' );
	}

	/**
	 * What this screen exports.
	 */
	public function exportKind(): string {
		return 'sources';
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$stats  = Plugin::instance()->stats();
		$params = $this->params();
		$siteId = $this->siteId();
		$range  = $params->range;

		$channels = $stats->channels( $siteId, $range );
		$dates    = $range->dates();

		$rows = [];

		foreach ( $stats->channelTrend( $siteId, $range ) as $row ) {
			$rows[] = [
				'date'     => (string) $row['date'],
				'channel'  => Channel::fromStored( (int) $row['channel'] )->label(),
				'sessions' => (int) $row['sessions'],
			];
		}

		$series = ChartData::foldToTop( ChartData::pivot( $rows, $dates, 'channel', 'sessions' ) );
		$labels = ChartData::axisLabels( $dates, false );

		$mixChart         = ChartData::stackedArea( $labels['axis'], ChartData::datasets( $series ) );
		$mixChart['full'] = $labels['full'];

		$sources = $stats->sources( $siteId, $range, 200 );
		$hosts   = array_values( array_filter( $sources, static fn ( array $row ): bool => '' !== $row['host'] ) );

		View::render(
			'admin/sources',
			[
				'params'       => $params,
				'channels'     => $channels,
				'channelTotal' => array_sum( array_column( $channels, 'sessions' ) ),
				'hosts'        => array_slice( $hosts, 0, 50 ),
				'maxHost'      => $hosts ? max( array_column( $hosts, 'sessions' ) ) : 0,
				'maxChannel'   => $channels ? max( array_column( $channels, 'sessions' ) ) : 0,
				'mixChart'     => $mixChart,
			]
		);
	}
}
