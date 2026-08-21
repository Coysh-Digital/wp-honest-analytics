<?php
/**
 * The Devices screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_typeTotal = array_sum( array_column( $types, 'sessions' ) );
?>
<div class="ha-card">
	<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Device type', 'honest-analytics' ); ?></h2></div>

	<div class="ha-card-body">
		<div class="ha-split">
			<div class="ha-split-chart">
				<?php
				View::render(
					'admin/partials/chart',
					[
						'id'      => 'ha-device-type',
						'payload' => $typeChart,
						'label'   => __( 'Sessions by device type.', 'honest-analytics' ),
						'classes' => 'ha-chart ha-chart-sm',
						// The same numbers sit in the legend beside it, so a
						// second hidden copy would only be noise for a screen
						// reader.
						'table'   => false,
					]
				);
				?>
			</div>

			<div class="ha-split-legend">
				<?php if ( [] !== $types ) : ?>
					<?php /* A table, not a list of divs: this legend is the doughnut's data, and it is the copy a screen reader reads. */ ?>
					<table class="ha-legend-table">
						<caption class="ha-visually-hidden"><?php esc_html_e( 'Sessions by device type', 'honest-analytics' ); ?></caption>

						<thead class="ha-visually-hidden">
							<tr>
								<th scope="col"><?php esc_html_e( 'Device type', 'honest-analytics' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Sessions', 'honest-analytics' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Share', 'honest-analytics' ); ?></th>
							</tr>
						</thead>

						<tbody>
							<?php foreach ( $types as $ha_index => $ha_type ) : ?>
								<tr class="ha-legend-row">
									<th scope="row">
										<span class="ha-swatch is-series-<?php echo esc_attr( (string) ( ( $ha_index % 5 ) + 1 ) ); ?>"></span>
										<?php echo esc_html( $ha_type['label'] ); ?>
									</th>
									<td class="ha-num"><b><?php echo esc_html( Format::count( $ha_type['sessions'] ) ); ?></b></td>
									<td class="ha-share"><?php echo esc_html( $ha_typeTotal > 0 ? Format::percent( $ha_type['sessions'] / $ha_typeTotal * 100 ) : '-' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="ha-muted"><?php esc_html_e( 'Nothing recorded for this period.', 'honest-analytics' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<div class="ha-grid-2">
	<?php
	foreach (
		[
			[ __( 'Browsers', 'honest-analytics' ), __( 'Browser', 'honest-analytics' ), $browsers ],
			[ __( 'Operating systems', 'honest-analytics' ), __( 'OS', 'honest-analytics' ), $systems ],
		] as $ha_block
	) :
		[ $ha_title, $ha_column, $ha_data ] = $ha_block;
		$ha_total                           = array_sum( array_column( $ha_data, 'sessions' ) );
		?>
		<div class="ha-card">
			<div class="ha-card-head"><h2 class="ha-card-title"><?php echo esc_html( $ha_title ); ?></h2></div>

			<div class="ha-card-body">
				<?php
				$ha_rows = [];

				foreach ( $ha_data as $ha_row ) {
					$ha_rows[] = [
						'label'    => (string) $ha_row['label'],
						'sessions' => Format::count( (int) $ha_row['sessions'] ),
						'share'    => $ha_total > 0 ? Format::percent( (int) $ha_row['sessions'] / $ha_total * 100 ) : '-',
						'_bar'     => (int) $ha_row['sessions'],
					];
				}

				View::render(
					'admin/partials/ranked-table',
					[
						'rows'    => $ha_rows,
						'max'     => $ha_data ? max( array_column( $ha_data, 'sessions' ) ) : 1,
						'columns' => [
							[
								'key'   => 'label',
								'label' => $ha_column,
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
	<?php endforeach; ?>
</div>

<p class="ha-footnote">
	<?php esc_html_e( 'Derived from the user-agent string and stored only as a browser name and major version - never a full version string, and never a device fingerprint. A doughnut is used only for device type, where the set is small and closed; browsers and operating systems have a long tail that a doughnut turns into unreadable slivers.', 'honest-analytics' ); ?>
</p>
