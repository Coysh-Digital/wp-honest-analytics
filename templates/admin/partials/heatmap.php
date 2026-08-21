<?php
/**
 * The day-and-hour grid, as a real table.
 *
 * @package HonestAnalytics
 *
 * @var array<string,mixed> $heatmap
 * @var string              $from
 * @var int                 $days
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$ha_grid = $heatmap;
?>
<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'When people visit', 'honest-analytics' ); ?></h2>
		<span class="ha-card-note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of days of hourly detail kept. */
					_n( 'Last %d day - the hourly retention window', 'Last %d days - the hourly retention window', $days, 'honest-analytics' ),
					$days
				)
			);
			?>
		</span>
	</div>

	<div class="ha-card-body">
		<?php if ( ! $ha_grid['covered'] ) : ?>
			<div class="ha-empty">
				<div class="ha-empty-title"><?php esc_html_e( 'Nothing to show here yet', 'honest-analytics' ); ?></div>
				<p class="ha-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of days. */
							__( 'This grid covers the last %d days, which is how long hourly detail is kept before it is folded into daily totals. Nothing has been recorded in that window yet.', 'honest-analytics' ),
							$days
						)
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="ha-scroll">
				<table class="ha-heatmap">
					<caption><?php esc_html_e( 'Pageviews by day of week and hour of day', 'honest-analytics' ); ?></caption>

					<thead>
						<tr>
							<td></td>
							<?php for ( $ha_hour = 0; $ha_hour < 24; $ha_hour++ ) : ?>
								<th scope="col">
									<?php if ( 0 === $ha_hour % 3 ) : ?>
										<?php echo esc_html( (string) $ha_hour ); ?>
									<?php else : ?>
										<span class="ha-visually-hidden"><?php echo esc_html( sprintf( '%02d:00', $ha_hour ) ); ?></span>
									<?php endif; ?>
								</th>
							<?php endfor; ?>
						</tr>
					</thead>

					<tbody>
						<?php foreach ( $ha_grid['cells'] as $ha_rowIndex => $ha_cells ) : ?>
							<?php
							$ha_weekday = $ha_grid['dayIndexes'][ $ha_rowIndex ];
							$ha_dayName = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $ha_weekday ) ) : (string) $ha_weekday;
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $ha_dayName ); ?></th>

								<?php foreach ( $ha_cells as $ha_hourIndex => $ha_cell ) : ?>
									<?php
									$ha_title = sprintf(
										/* translators: 1: weekday, 2: hour, 3: view count. */
										__( '%1$s %2$02d:00 - %3$s views', 'honest-analytics' ),
										$ha_dayName,
										$ha_hourIndex,
										number_format_i18n( $ha_cell['views'] )
									);
									?>
									<td data-bucket="<?php echo esc_attr( (string) $ha_cell['bucket'] ); ?>" title="<?php echo esc_attr( $ha_title ); ?>">
										<span class="ha-visually-hidden"><?php echo esc_html( $ha_title ); ?></span>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="ha-heat-key">
				<span><?php esc_html_e( 'Quieter', 'honest-analytics' ); ?></span>
				<?php for ( $ha_bucket = 0; $ha_bucket <= 6; $ha_bucket++ ) : ?>
					<span class="ha-heat-chip" data-bucket="<?php echo esc_attr( (string) $ha_bucket ); ?>" aria-hidden="true"></span>
				<?php endfor; ?>
				<span><?php esc_html_e( 'Busier', 'honest-analytics' ); ?></span>

				<span class="ha-heat-peak">
					<?php
					$ha_peakDay = $wp_locale ? $wp_locale->get_weekday( $ha_grid['peak']['day'] ) : '';

					echo esc_html(
						sprintf(
							/* translators: 1: weekday, 2: hour, 3: view count. */
							__( 'Peak: %1$s %2$02d:00, %3$s views', 'honest-analytics' ),
							$ha_peakDay,
							$ha_grid['peak']['hour'],
							number_format_i18n( $ha_grid['peak']['views'] )
						)
					);
					?>
				</span>
			</div>
		<?php endif; ?>
	</div>
</div>
