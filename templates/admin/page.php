<?php
/**
 * The single-page detail view.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Support\Timezone;
use HonestAnalytics\Stats\DateRange;

use HonestAnalytics\Admin\Screens\DashboardScreen;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// null exactly when no comparison is active, never merely when the baseline
// was zero - so every delta below is absent rather than showing a change
// against a period the toggle says is off. Copied to a real local variable
// so a static analyser can see it defined; $previous itself only exists here
// because View::render() extract()s it in.
$ha_previous = $previous;

$ha_delta = static fn ( int|float $current, string $key ): ?float =>
	null !== $ha_previous ? DashboardScreen::delta( $current, $ha_previous[ $key ] ) : null;

$ha_kpis = [
	[
		'label' => __( 'Views', 'honest-analytics' ),
		'value' => Format::count( $totals['views'] ),
		'delta' => $ha_delta( $totals['views'], 'views' ),
		'note'  => $compareNote,
	],
	[
		'label' => __( 'Unique visitors', 'honest-analytics' ),
		'value' => Format::count( $totals['uniques'] ),
		'delta' => $ha_delta( $totals['uniques'], 'uniques' ),
		'note'  => sprintf( /* translators: %s: accuracy. */ __( 'daily uniques, %s', 'honest-analytics' ), $accuracy ),
	],
	[
		'label' => __( 'Entrances', 'honest-analytics' ),
		'value' => Format::count( $totals['entrances'] ),
		'delta' => $ha_delta( $totals['entrances'], 'entrances' ),
		'note'  => __( 'sessions starting here', 'honest-analytics' ),
	],
	[
		'label'   => __( 'Bounce rate', 'honest-analytics' ),
		'value'   => $totals['entrances'] > 0 ? Format::percent( (float) $totals['bounceRate'] ) : '-',
		'delta'   => $totals['entrances'] > 0 ? $ha_delta( $totals['bounceRate'], 'bounceRate' ) : null,
		'inverse' => true,
		'note'    => $totals['entrances'] > 0
			? __( 'of entrances', 'honest-analytics' )
			: __( 'no sessions started here', 'honest-analytics' ),
	],
	[
		'label' => __( 'Avg time on page', 'honest-analytics' ),
		'value' => $beacon ? Format::duration( (int) $totals['avgDwellMs'] ) : '-',
		'delta' => $beacon ? $ha_delta( $totals['avgDwellMs'], 'avgDwellMs' ) : null,
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
					'max'     => Format::largest( $sources, 'views' ),
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
							Timezone::date( (int) Timezone::middayOn( $sourcesSince ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php
	/**
	 * Fires after the sources card on a page's detail view.
	 *
	 * Where anything with more to say about one page adds its own cards -
	 * how far down it was read, what was clicked on it, what people searched
	 * to reach it.
	 *
	 * @param string    $path  The page being looked at.
	 * @param DateRange $range The period on screen.
	 */
	do_action( 'honest_analytics_page_detail_cards', $path, $params->range );
	?>
</div>
