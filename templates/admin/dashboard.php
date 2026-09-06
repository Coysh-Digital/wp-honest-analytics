<?php
/**
 * The Dashboard screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Support\Timezone;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Devices\DeviceType;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php
View::render(
	'admin/partials/realtime-banner',
	[
		'visitors' => (int) $realtime['visitors'],
		'url'      => menu_page_url( 'honest-analytics-realtime', false ),
	]
);
?>

<?php if ( null !== $emptyHint ) : ?>
	<div class="ha-card">
		<div class="ha-card-body">
			<div class="ha-empty">
				<div class="ha-empty-title"><?php echo esc_html( $emptyHint['title'] ); ?></div>
				<p class="ha-muted ha-prose"><?php echo esc_html( $emptyHint['body'] ); ?></p>
			</div>
		</div>
	</div>
<?php else : ?>

	<?php View::render( 'admin/partials/kpis', [ 'kpis' => $kpis ] ); ?>

	<p class="ha-footnote">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: accuracy, e.g. "±1.6%". */
				__( 'Unique visitors are daily unique estimates (%s): the hashing salt is destroyed every 24 hours, so a visitor returning on three days counts three times. Pageviews and sessions are exact.', 'honest-analytics' ),
				$accuracy
			)
		);
		?>
	</p>

	<div class="ha-card">
		<div class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'Traffic', 'honest-analytics' ); ?></h2>

			<div class="ha-legend">
				<span class="ha-legend-item"><span class="ha-swatch is-views"></span><?php esc_html_e( 'Pageviews', 'honest-analytics' ); ?></span>
				<?php if ( ! empty( $trendChart['hasUniques'] ) ) : ?>
					<span class="ha-legend-item"><span class="ha-swatch is-uniques"></span><?php esc_html_e( 'Unique visitors', 'honest-analytics' ); ?></span>
				<?php endif; ?>
				<?php foreach ( $trendChart['datasets'] as $ha_trend_dataset ) : ?>
					<?php if ( 'compare' === $ha_trend_dataset['token'] ) : ?>
						<span class="ha-legend-item"><span class="ha-swatch is-compare is-dashed"></span><?php echo esc_html( $ha_trend_dataset['label'] ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="ha-card-body">
			<?php
			View::render(
				'admin/partials/chart',
				[
					'id'      => 'ha-trend',
					'payload' => $trendChart,
					'label'   => __( 'Pageviews and unique visitors over the selected period.', 'honest-analytics' ),
					'caption' => __( 'Pageviews and unique visitors', 'honest-analytics' ),
					'classes' => 'ha-chart',
				]
			);
			?>

			<?php if ( null !== ( $boundary ?? null ) ) : ?>
				<p class="ha-boundary">
					<span class="ha-boundary-mark"><?php echo esc_html( Timezone::format( 'j M Y', (int) Timezone::middayOn( $boundary['date'] ) ) ); ?></span>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: a list of analytics tool names. */
								__( 'Figures before this date were imported from %s. Measurement methods differ, so a step in the line here is the change of method rather than a change in your traffic.', 'honest-analytics' ),
								implode( ', ', $boundary['sources'] )
							)
						);
						?>
					</span>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php
	View::render(
		'admin/partials/heatmap',
		[
			'heatmap' => $heatmap,
			'window'  => $heatmapWindow,
			'days'    => $hourlyDays,
		]
	);
	?>

	<div class="ha-card">
		<div class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'Top pages', 'honest-analytics' ); ?></h2>
			<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-pages' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
		</div>

		<div class="ha-card-body">
			<?php
			$ha_rows = [];

			foreach ( $topPages as $ha_page ) {
				$ha_rows[] = [
					'path'  => $ha_page['path'],
					'views' => Format::count( $ha_page['views'] ),
					'time'  => $ha_page['avgDwellMs'] > 0 ? Format::duration( $ha_page['avgDwellMs'] ) : '-',
					'_bar'  => $ha_page['views'],
					'_url'  => \HonestAnalytics\Dimensions\DimensionType::OTHER_VALUE === $ha_page['path']
						? null
						: $params->url( [ 'path' => $ha_page['path'] ], 'honest-analytics-pages' ),
				];
			}

			View::render(
				'admin/partials/ranked-table',
				[
					'rows'    => $ha_rows,
					'max'     => Format::largest( $topPages, 'views' ),
					'columns' => [
						[
							'key'   => 'path',
							'label' => __( 'Page', 'honest-analytics' ),
							'mono'  => true,
						],
						[
							'key'     => 'views',
							'label'   => __( 'Views', 'honest-analytics' ),
							'numeric' => true,
						],
						[
							'key'   => 'time',
							'label' => __( 'Avg time', 'honest-analytics' ),
						],
					],
				]
			);
			?>
		</div>
	</div>

	<div class="ha-grid-2">

		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'Channels', 'honest-analytics' ); ?></h2>
				<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-sources' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
			</div>

			<div class="ha-card-body">
				<?php
				$ha_total = array_sum( array_column( $channels, 'sessions' ) );
				$ha_rows  = [];

				foreach ( $channels as $ha_channel ) {
					$ha_rows[] = [
						'label'    => Channel::fromStored( $ha_channel['channel'] )->label(),
						'sessions' => Format::count( $ha_channel['sessions'] ),
						'share'    => $ha_total > 0 ? Format::percent( $ha_channel['sessions'] / $ha_total * 100 ) : '-',
						'_bar'     => $ha_channel['sessions'],
					];
				}

				View::render(
					'admin/partials/ranked-table',
					[
						'rows'    => $ha_rows,
						'max'     => Format::largest( $channels, 'sessions' ),
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
								'key'   => 'share',
								'label' => __( 'Share', 'honest-analytics' ),
							],
						],
					]
				);
				?>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'Devices', 'honest-analytics' ); ?></h2>
				<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-devices' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
			</div>

			<div class="ha-card-body">
				<?php
				$ha_deviceTotal = array_sum( array_column( $devices, 'sessions' ) );
				?>
				<div class="ha-split-legend">
					<?php foreach ( $devices as $ha_index => $ha_device ) : ?>
						<div class="ha-legend-row">
							<span class="ha-swatch is-series-<?php echo esc_attr( (string) ( ( $ha_index % 5 ) + 1 ) ); ?>"></span>
							<span><?php echo esc_html( DeviceType::fromStored( (int) $ha_device['label'] )->label() ); ?></span>
							<b><?php echo esc_html( Format::count( (int) $ha_device['sessions'] ) ); ?></b>
							<span class="ha-share"><?php echo esc_html( $ha_deviceTotal > 0 ? Format::percent( (int) $ha_device['sessions'] / $ha_deviceTotal * 100 ) : '-' ); ?></span>
						</div>
					<?php endforeach; ?>

					<?php if ( [] === $devices ) : ?>
						<p class="ha-muted"><?php esc_html_e( 'Nothing recorded for this period.', 'honest-analytics' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'Post types', 'honest-analytics' ); ?></h2>
				<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-content' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
			</div>

			<div class="ha-card-body">
				<?php
				$ha_rows = [];

				foreach ( $postTypes as $ha_type ) {
					$ha_rows[] = [
						'label'   => $ha_type['label'],
						'views'   => Format::count( $ha_type['views'] ),
						'perPost' => Format::count( $ha_type['perPost'] ),
						'_bar'    => $ha_type['views'],
					];
				}

				View::render(
					'admin/partials/ranked-table',
					[
						'rows'    => $ha_rows,
						'max'     => Format::largest( $postTypes, 'views' ),
						'columns' => [
							[
								'key'   => 'label',
								'label' => __( 'Post type', 'honest-analytics' ),
							],
							[
								'key'     => 'views',
								'label'   => __( 'Views', 'honest-analytics' ),
								'numeric' => true,
							],
							[
								'key'   => 'perPost',
								'label' => __( 'Per post', 'honest-analytics' ),
							],
						],
					]
				);
				?>
			</div>
		</div>

		<?php
		/**
		 * Fires between the traffic cards and the content cards.
		 *
		 * Where anything with another way of reading the same period adds its
		 * own cards - what converted, which campaigns brought people, where they
		 * were, what was excluded from the figures above.
		 *
		 * @param RequestParams $params The toolbar state.
		 * @param DateRange     $range  The period on screen.
		 */
		do_action( 'honest_analytics_dashboard_cards', $params, $range );
		?>

	</div>
<?php endif; ?>
