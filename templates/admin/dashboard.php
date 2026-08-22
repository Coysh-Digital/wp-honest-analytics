<?php
/**
 * The Dashboard screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

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
					<span class="ha-boundary-mark"><?php echo esc_html( wp_date( 'j M Y', (int) strtotime( $boundary['date'] ) ) ); ?></span>
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
			'from'    => $heatmapFrom,
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
					'max'     => $topPages ? max( array_column( $topPages, 'views' ) ) : 1,
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
						'max'     => $channels ? max( array_column( $channels, 'sessions' ) ) : 1,
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
						'max'     => $postTypes ? max( array_column( $postTypes, 'views' ) ) : 1,
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

		<?php if ( $isPro ) : ?>
			<div class="ha-card">
				<div class="ha-card-head">
					<h2 class="ha-card-title"><?php esc_html_e( 'Goals', 'honest-analytics' ); ?></h2>
					<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-goals' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
				</div>

				<div class="ha-card-body">
					<?php
					$ha_rows = [];

					foreach ( $goals as $ha_goal ) {
						$ha_rows[] = [
							'label'       => $ha_goal['name'],
							'conversions' => Format::count( $ha_goal['conversions'] ),
							'rate'        => Format::percent( (float) $ha_goal['rate'] ),
							'_bar'        => $ha_goal['conversions'],
						];
					}

					View::render(
						'admin/partials/ranked-table',
						[
							'rows'    => $ha_rows,
							'max'     => $goals ? max( array_column( $goals, 'conversions' ) ) : 1,
							'empty'   => __( 'No goals defined yet.', 'honest-analytics' ),
							'columns' => [
								[
									'key'   => 'label',
									'label' => __( 'Goal', 'honest-analytics' ),
								],
								[
									'key'     => 'conversions',
									'label'   => __( 'Conversions', 'honest-analytics' ),
									'numeric' => true,
								],
								[
									'key'   => 'rate',
									'label' => __( 'Rate', 'honest-analytics' ),
								],
							],
						]
					);
					?>
				</div>
			</div>
		<?php else : ?>
			<?php
			View::render(
				'admin/partials/pro-placeholder',
				[
					'title'       => __( 'Goals', 'honest-analytics' ),
					'slug'        => 'honest-analytics-goals',
					'description' => __( 'Would tell you how many sessions completed a form submission, a download or a page visit you care about.', 'honest-analytics' ),
				]
			);
			?>
		<?php endif; ?>

		<?php if ( $isPro ) : ?>
			<div class="ha-card">
				<div class="ha-card-head">
					<h2 class="ha-card-title"><?php esc_html_e( 'Campaigns', 'honest-analytics' ); ?></h2>
					<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-campaigns' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
				</div>

				<div class="ha-card-body">
					<?php
					$ha_rows = [];

					foreach ( $campaigns as $ha_campaign ) {
						$ha_rows[] = [
							'label'    => '' !== $ha_campaign['campaign'] ? $ha_campaign['campaign'] : $ha_campaign['source'],
							'sessions' => Format::count( (int) $ha_campaign['sessions'] ),
							'_bar'     => (float) $ha_campaign['sessions'],
						];
					}

					View::render(
						'admin/partials/ranked-table',
						[
							'rows'    => $ha_rows,
							'max'     => $campaigns ? max( array_column( $campaigns, 'sessions' ) ) : 1,
							'empty'   => __( 'No tagged links have brought anybody here in this period.', 'honest-analytics' ),
							'columns' => [
								[
									'key'   => 'label',
									'label' => __( 'Campaign', 'honest-analytics' ),
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

			<div class="ha-card">
				<div class="ha-card-head">
					<h2 class="ha-card-title"><?php esc_html_e( 'Locations', 'honest-analytics' ); ?></h2>
					<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-locations' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
				</div>

				<div class="ha-card-body">
					<?php
					$ha_rows  = [];
					$ha_total = array_sum( array_column( $countries, 'sessions' ) );

					foreach ( $countries as $ha_country ) {
						$ha_rows[] = [
							'label'    => (string) $ha_country['countryCode'],
							'sessions' => Format::count( (int) $ha_country['sessions'] ),
							'share'    => $ha_total > 0 ? Format::percent( (int) $ha_country['sessions'] / $ha_total * 100 ) : '-',
							'_bar'     => (int) $ha_country['sessions'],
						];
					}

					View::render(
						'admin/partials/ranked-table',
						[
							'rows'    => $ha_rows,
							'max'     => $countries ? max( array_column( $countries, 'sessions' ) ) : 1,
							'empty'   => __( 'Nothing yet. Country reporting needs a local database, which you can install on the Locations screen.', 'honest-analytics' ),
							'columns' => [
								[
									'key'   => 'label',
									'label' => __( 'Country', 'honest-analytics' ),
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
		<?php else : ?>
			<?php
			View::render(
				'admin/partials/pro-placeholder',
				[
					'title'       => __( 'Campaigns', 'honest-analytics' ),
					'slug'        => 'honest-analytics-campaigns',
					'description' => __( 'Would show which tagged links brought people here, and what those visits did next.', 'honest-analytics' ),
				]
			);

			View::render(
				'admin/partials/pro-placeholder',
				[
					'title'       => __( 'Locations', 'honest-analytics' ),
					'slug'        => 'honest-analytics-locations',
					'description' => __( 'Would show which countries visitors came from, resolved locally and never stored against anybody.', 'honest-analytics' ),
				]
			);
			?>
		<?php endif; ?>

		<?php if ( ! $isPro ) : ?>
			<?php
			View::render(
				'admin/partials/pro-placeholder',
				[
					'title'       => __( 'Crawlers', 'honest-analytics' ),
					'slug'        => 'honest-analytics-crawlers',
					'description' => __( 'Would name the bots that were kept out of every figure above, which is usually the answer to why your server log counts more than this does.', 'honest-analytics' ),
				]
			);
			?>
		<?php else : ?>
		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'Crawlers', 'honest-analytics' ); ?></h2>
				<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-crawlers' ) ); ?>"><?php esc_html_e( 'View all', 'honest-analytics' ); ?></a>
			</div>

			<div class="ha-card-body">
				<p class="ha-muted ha-tight-below">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of requests. */
							__( '%s crawler requests, excluded from every figure above.', 'honest-analytics' ),
							number_format_i18n( $crawlerHits )
						)
					);
					?>
				</p>

				<?php
				$ha_rows = [];

				foreach ( $crawlers as $ha_crawler ) {
					$ha_rows[] = [
						'label'    => $ha_crawler['label'],
						'requests' => Format::count( (int) $ha_crawler['requests'] ),
						'_bar'     => (int) $ha_crawler['requests'],
					];
				}

				View::render(
					'admin/partials/ranked-table',
					[
						'rows'    => $ha_rows,
						'max'     => $crawlers ? max( array_column( $crawlers, 'requests' ) ) : 1,
						'empty'   => __( 'No crawler traffic recorded.', 'honest-analytics' ),
						'columns' => [
							[
								'key'   => 'label',
								'label' => __( 'Crawler', 'honest-analytics' ),
							],
							[
								'key'     => 'requests',
								'label'   => __( 'Requests', 'honest-analytics' ),
								'numeric' => true,
							],
						],
					]
				);
				?>
			</div>
		</div>
		<?php endif; ?>

	</div>
<?php endif; ?>
