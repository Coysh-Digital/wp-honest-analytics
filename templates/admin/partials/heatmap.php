<?php
/**
 * The day-and-hour grid, as a real table.
 *
 * @package HonestAnalytics
 *
 * @var array<string,mixed>                            $heatmap
 * @var array{from:string,to:string,clipped:bool}|null  $window
 * @var int                                             $days
 */

declare(strict_types=1);

use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$ha_grid = $heatmap;

// Where to go to widen the window. Settings is one long page with no tabs and
// every control's id is `ha-` plus its key, so the anchor lands on the field
// rather than at the top of a screen full of other settings.
$ha_settings = admin_url( 'admin.php?page=honest-analytics-settings#ha-hourlyWindowDays' );

$ha_dates = null !== $window
	? sprintf(
		/* translators: 1: start date, 2: end date. */
		__( '%1$s - %2$s', 'honest-analytics' ),
		wp_date( 'j M Y', (int) Timezone::middayOn( $window['from'] ) ),
		wp_date( 'j M Y', (int) Timezone::middayOn( $window['to'] ) )
	)
	: '';

// Why the grid is empty, which is two different answers. A period older than
// the window had its hours recorded and then deliberately discarded, and
// saying "nothing to show" there would read as "nobody came".
if ( null === $window ) {
	$ha_empty_title = __( 'Older than the hourly window', 'honest-analytics' );
	$ha_empty_body  = sprintf(
		/* translators: %d: number of days of hourly detail kept. */
		_n(
			'Hourly detail is kept for %d day before it is folded into daily totals, and this period is older than that, so the hour of each visit is no longer recorded for it. The pageviews themselves are still counted everywhere else on this screen.',
			'Hourly detail is kept for %d days before it is folded into daily totals, and this period is older than that, so the hour of each visit is no longer recorded for it. The pageviews themselves are still counted everywhere else on this screen.',
			$days,
			'honest-analytics'
		),
		$days
	);
} else {
	// Named by the dates it actually covered, not by the setting: a range
	// shorter than the window covers less than the window, and quoting the
	// setting there would be the same quiet inaccuracy in a smaller place.
	$ha_empty_title = __( 'Nothing to show here yet', 'honest-analytics' );
	$ha_empty_body  = sprintf(
		/* translators: %s: a date range, for example "9 Nov 2026 - 15 Nov 2026". */
		__( 'This grid covers %s, which is as far back as hourly detail is kept before it is folded into daily totals. Nothing has been recorded in it yet.', 'honest-analytics' ),
		$ha_dates
	);
}
?>
<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'When people visit', 'honest-analytics' ); ?></h2>
		<span class="ha-card-note">
			<?php if ( null === $window ) : ?>
				<?php esc_html_e( 'Outside the hourly window', 'honest-analytics' ); ?>
			<?php elseif ( $window['clipped'] ) : ?>
				<?php
				echo esc_html( $ha_dates ) . ' - ';
				echo esc_html(
					sprintf(
						/* translators: %d: number of days of hourly detail kept. */
						_n(
							'hourly detail is only kept for %d day, so this covers the recent part of your range.',
							'hourly detail is only kept for %d days, so this covers the recent part of your range.',
							$days,
							'honest-analytics'
						),
						$days
					)
				);
				?>
				<a href="<?php echo esc_url( $ha_settings ); ?>"><?php esc_html_e( 'Keep hourly detail for longer', 'honest-analytics' ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $ha_dates ); ?>
			<?php endif; ?>
		</span>
	</div>

	<div class="ha-card-body">
		<?php if ( ! $ha_grid['covered'] ) : ?>
			<div class="ha-empty">
				<div class="ha-empty-title"><?php echo esc_html( $ha_empty_title ); ?></div>
				<p class="ha-muted"><?php echo esc_html( $ha_empty_body ); ?></p>
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
