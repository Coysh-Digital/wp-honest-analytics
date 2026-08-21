<?php
/**
 * The single-page detail view.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Admin\Screens\DashboardScreen;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_kpis = [
	[
		'label' => __( 'Views', 'honest-analytics' ),
		'value' => Format::count( $totals['views'] ),
		'delta' => DashboardScreen::delta( $totals['views'], $previous['views'] ),
		'note'  => __( 'vs previous period', 'honest-analytics' ),
	],
	[
		'label' => __( 'Unique visitors', 'honest-analytics' ),
		'value' => Format::count( $totals['uniques'] ),
		'delta' => DashboardScreen::delta( $totals['uniques'], $previous['uniques'] ),
		'note'  => sprintf( /* translators: %s: accuracy. */ __( 'daily uniques, %s', 'honest-analytics' ), $accuracy ),
	],
	[
		'label' => __( 'Entrances', 'honest-analytics' ),
		'value' => Format::count( $totals['entrances'] ),
		'delta' => DashboardScreen::delta( $totals['entrances'], $previous['entrances'] ),
		'note'  => __( 'sessions starting here', 'honest-analytics' ),
	],
	[
		'label'   => __( 'Bounce rate', 'honest-analytics' ),
		'value'   => $totals['entrances'] > 0 ? Format::percent( (float) $totals['bounceRate'] ) : '-',
		'delta'   => $totals['entrances'] > 0 ? DashboardScreen::delta( $totals['bounceRate'], $previous['bounceRate'] ) : null,
		'inverse' => true,
		'note'    => $totals['entrances'] > 0
			? __( 'of entrances', 'honest-analytics' )
			: __( 'no sessions started here', 'honest-analytics' ),
	],
	[
		'label' => __( 'Avg time on page', 'honest-analytics' ),
		'value' => $beacon ? Format::duration( (int) $totals['avgDwellMs'] ) : '-',
		'delta' => $beacon ? DashboardScreen::delta( $totals['avgDwellMs'], $previous['avgDwellMs'] ) : null,
		'note'  => $beacon ? __( 'needs the beacon', 'honest-analytics' ) : __( 'needs hybrid or client mode', 'honest-analytics' ),
	],
];
?>
<p class="ha-back"><a href="<?php echo esc_url( $params->url( [ 'path' => null ] ) ); ?>">&larr; <?php esc_html_e( 'Pages', 'honest-analytics' ); ?></a></p>

<div class="ha-header is-flush">
	<?php /* The path is the screen's h1, rendered by the layout. */ ?>
	<?php if ( null !== $editUrl ) : ?>
		<a class="button button-secondary" href="<?php echo esc_url( $editUrl ); ?>"><?php esc_html_e( 'Edit post', 'honest-analytics' ); ?></a>
	<?php else : ?>
		<span class="ha-muted"><?php esc_html_e( 'Not a WordPress post', 'honest-analytics' ); ?></span>
	<?php endif; ?>
</div>

<?php View::render( 'admin/partials/kpis', [ 'kpis' => $ha_kpis ] ); ?>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'Traffic', 'honest-analytics' ); ?></h2>

		<div class="ha-legend">
			<span class="ha-legend-item"><span class="ha-swatch is-views"></span><?php esc_html_e( 'Views', 'honest-analytics' ); ?></span>
			<?php if ( ! empty( $trendChart['hasUniques'] ) ) : ?>
				<span class="ha-legend-item"><span class="ha-swatch is-uniques"></span><?php esc_html_e( 'Unique visitors', 'honest-analytics' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<div class="ha-card-body">
		<?php
		View::render(
			'admin/partials/chart',
			[
				'id'      => 'ha-page-trend',
				'payload' => $trendChart,
				'label'   => __( 'Views and unique visitors for this page.', 'honest-analytics' ),
				'caption' => __( 'Views and unique visitors for this page', 'honest-analytics' ),
				'classes' => 'ha-chart ha-chart-sm',
			]
		);
		?>
	</div>
</div>

<div class="ha-grid-2">
	<div class="ha-card">
		<div class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'How visitors reached this page', 'honest-analytics' ); ?></h2>
		</div>

		<div class="ha-card-body">
			<?php
			$ha_rows = [];

			foreach ( $sources as $ha_source ) {
				$ha_rows[] = [
					'label'   => '' !== (string) $ha_source['host'] ? (string) $ha_source['host'] : __( 'None', 'honest-analytics' ),
					'channel' => Channel::fromStored( (int) $ha_source['channel'] )->label(),
					'views'   => Format::count( (int) $ha_source['views'] ),
					'_bar'    => (int) $ha_source['views'],
				];
			}

			View::render(
				'admin/partials/ranked-table',
				[
					'rows'    => $ha_rows,
					'max'     => $sources ? max( array_column( $sources, 'views' ) ) : 1,
					'empty'   => __( 'No sources recorded for this page in this period.', 'honest-analytics' ),
					'columns' => [
						[
							'key'   => 'label',
							'label' => __( 'Referrer', 'honest-analytics' ),
							'mono'  => true,
						],
						[
							'key'   => 'channel',
							'label' => __( 'Channel', 'honest-analytics' ),
						],
						[
							'key'     => 'views',
							'label'   => __( 'Views', 'honest-analytics' ),
							'numeric' => true,
						],
					],
				]
			);
			?>

			<?php if ( null !== $sourcesSince ) : ?>
				<p class="ha-muted ha-fine">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: a date. */
							__( 'Fills forward only. Collection for this card began %s; views before then are counted, but their source was not kept. The referrer shown is the one the session arrived by, not the previous page on this site.', 'honest-analytics' ),
							wp_date( 'j F Y', (int) strtotime( $sourcesSince ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $isPro ) : ?>
		<div class="ha-card">
			<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Scroll depth', 'honest-analytics' ); ?></h2></div>

			<div class="ha-card-body">
				<?php if ( null === $scroll ) : ?>
					<p class="ha-muted"><?php esc_html_e( 'Nothing recorded for this page yet.', 'honest-analytics' ); ?></p>
				<?php else : ?>
					<?php
					$ha_base = max( 1, (int) $scroll['reached25'] );

					foreach ( [ 25, 50, 75, 100 ] as $ha_depth ) :
						$ha_reached = (int) $scroll[ 'reached' . $ha_depth ];
						$ha_share   = round( $ha_reached / $ha_base * 100, 1 );
						?>
						<div class="ha-gap-below">
							<div class="ha-meter-row">
								<span><?php echo esc_html( sprintf( /* translators: %d: percentage of the page. */ __( 'Reached %d%%', 'honest-analytics' ), $ha_depth ) ); ?></span>
								<span class="ha-muted"><?php echo esc_html( number_format_i18n( $ha_reached ) ); ?></span>
							</div>
							<div class="ha-meter"><div class="ha-meter-fill" style="width:<?php echo esc_attr( (string) min( 100, $ha_share ) ); ?>%"></div></div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<?php if ( ! $beacon ) : ?>
					<p class="ha-muted ha-fine is-snug"><?php esc_html_e( 'Requires hybrid or client tracking mode.', 'honest-analytics' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Events on this page', 'honest-analytics' ); ?></h2></div>

			<div class="ha-card-body">
				<?php
				$ha_rows = [];

				foreach ( $events as $ha_event ) {
					$ha_rows[] = [
						'label' => $ha_event['label'],
						'hits'  => Format::count( $ha_event['hits'] ),
						'_bar'  => $ha_event['hits'],
					];
				}

				View::render(
					'admin/partials/ranked-table',
					[
						'rows'    => $ha_rows,
						'max'     => $events ? max( array_column( $events, 'hits' ) ) : 1,
						'empty'   => $beacon
							? __( 'No events recorded on this page.', 'honest-analytics' )
							: __( 'Events arrive on the beacon, which server-only mode does not use.', 'honest-analytics' ),
						'columns' => [
							[
								'key'   => 'label',
								'label' => __( 'Event', 'honest-analytics' ),
							],
							[
								'key'     => 'hits',
								'label'   => __( 'Count', 'honest-analytics' ),
								'numeric' => true,
							],
						],
					]
				);
				?>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Outbound links and downloads', 'honest-analytics' ); ?></h2></div>

			<div class="ha-card-body">
				<?php
				$ha_rows = [];

				foreach ( $outbound as $ha_link ) {
					$ha_rows[] = [
						'label' => (string) ( $ha_link['url'] ?? $ha_link['host'] ),
						'hits'  => Format::count( (int) $ha_link['hits'] ),
						'_bar'  => (int) $ha_link['hits'],
					];
				}

				View::render(
					'admin/partials/ranked-table',
					[
						'rows'    => $ha_rows,
						'max'     => $outbound ? max( array_column( $outbound, 'hits' ) ) : 1,
						'empty'   => __( 'No clicks away from this page recorded.', 'honest-analytics' ),
						'columns' => [
							[
								'key'   => 'label',
								'label' => __( 'Destination', 'honest-analytics' ),
								'mono'  => true,
							],
							[
								'key'     => 'hits',
								'label'   => __( 'Clicks', 'honest-analytics' ),
								'numeric' => true,
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
				'title'       => __( 'Scroll depth', 'honest-analytics' ),
				'description' => __( 'Would tell you how far down this page people actually read before leaving.', 'honest-analytics' ),
			]
		);

		View::render(
			'admin/partials/pro-placeholder',
			[
				'title'       => __( 'Events and outbound clicks', 'honest-analytics' ),
				'description' => __( 'Would tell you which buttons were pressed on this page and which links took people away from it.', 'honest-analytics' ),
			]
		);
		?>
	<?php endif; ?>
</div>
