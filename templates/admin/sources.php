<?php
/**
 * The Sources screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'Channel mix', 'honest-analytics' ); ?></h2>

		<div class="ha-legend">
			<?php foreach ( $mixChart['datasets'] as $ha_dataset ) : ?>
				<span class="ha-legend-item">
					<span class="ha-swatch is-<?php echo esc_attr( $ha_dataset['token'] ); ?>"></span>
					<?php echo esc_html( $ha_dataset['label'] ); ?>
				</span>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="ha-card-body">
		<?php
		View::render(
			'admin/partials/chart',
			[
				'id'      => 'ha-channel-mix',
				'payload' => $mixChart,
				'label'   => __( 'Sessions by channel over the selected period.', 'honest-analytics' ),
				'caption' => __( 'Sessions by channel and day', 'honest-analytics' ),
			]
		);
		?>
	</div>
</div>

<div class="ha-grid-2">
	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Channels', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<?php
			$ha_rows = [];

			foreach ( $channels as $ha_channel ) {
				$ha_rows[] = [
					'label'    => Channel::fromStored( $ha_channel['channel'] )->label(),
					'sessions' => Format::count( $ha_channel['sessions'] ),
					'bounce'   => Format::percent( (float) $ha_channel['bounceRate'] ),
					'_bar'     => $ha_channel['sessions'],
				];
			}

			View::render(
				'admin/partials/ranked-table',
				[
					'rows'    => $ha_rows,
					'max'     => $maxChannel,
					'columns' => [
						[
							'key'   => 'label',
							'label' => __( 'Channel', 'honest-analytics' ),
						],
						[
							'key'     => 'sessions',
							'label'   => __( 'Sessions', 'honest-analytics' ),
							'numeric' => true,
						],
						[
							'key'   => 'bounce',
							'label' => __( 'Bounce', 'honest-analytics' ),
						],
					],
				]
			);
			?>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Referring hosts', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<?php
			$ha_rows = [];

			foreach ( $hosts as $ha_host ) {
				$ha_rows[] = [
					'label'    => $ha_host['host'],
					'sessions' => Format::count( $ha_host['sessions'] ),
					'_bar'     => $ha_host['sessions'],
				];
			}

			View::render(
				'admin/partials/ranked-table',
				[
					'rows'    => $ha_rows,
					'max'     => $maxHost,
					'empty'   => __( 'No referring hosts recorded. Every visit in this period arrived directly.', 'honest-analytics' ),
					'columns' => [
						[
							'key'   => 'label',
							'label' => __( 'Host', 'honest-analytics' ),
							'mono'  => true,
						],
						[
							'key'     => 'sessions',
							'label'   => __( 'Sessions', 'honest-analytics' ),
							'numeric' => true,
						],
					],
				]
			);
			?>
		</div>
	</div>
</div>

<p class="ha-footnote">
	<?php esc_html_e( 'Only the referring host is stored, never the full referrer URL, which can carry search terms. The referrer is read when PHP renders the page; the beacon never sends one, because a browser-supplied referrer is forgeable. A session that enters on a cached page is therefore reported as Direct. Campaign tags travel in the URL and are unaffected.', 'honest-analytics' ); ?>
</p>
