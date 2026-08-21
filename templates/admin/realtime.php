<?php
/**
 * The Real-time screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="ha-muted ha-lede is-inline">
	<span class="ha-live-dot" aria-hidden="true"></span>
	<?php esc_html_e( 'Updating every 15 seconds', 'honest-analytics' ); ?>
</p>

<div data-ha-realtime>
	<div class="ha-kpis">
		<div class="ha-kpi">
			<div class="ha-kpi-label"><?php esc_html_e( 'Visitors now', 'honest-analytics' ); ?></div>
			<div class="ha-hero" data-ha-count="visitors"><?php echo esc_html( number_format_i18n( $snapshot['visitors'] ) ); ?></div>
		</div>

		<div class="ha-kpi">
			<div class="ha-kpi-label"><?php esc_html_e( 'Pages viewed', 'honest-analytics' ); ?></div>
			<div class="ha-hero" data-ha-count="pageviews"><?php echo esc_html( number_format_i18n( $snapshot['pageviews'] ) ); ?></div>
		</div>

		<div class="ha-kpi">
			<div class="ha-kpi-label"><?php esc_html_e( 'Pages / visitor', 'honest-analytics' ); ?></div>
			<div class="ha-hero" data-ha-count="pagesPerVisitor"><?php echo esc_html( $snapshot['visitors'] > 0 ? number_format_i18n( $snapshot['pagesPerVisitor'], 1 ) : '-' ); ?></div>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'Active sessions', 'honest-analytics' ); ?></h2>
			<span class="ha-card-note" aria-live="polite" data-ha-updated><?php esc_html_e( 'Updated just now', 'honest-analytics' ); ?></span>
		</div>

		<div class="ha-card-body">
			<div class="ha-scroll">
				<table class="ha-table is-wider">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Current page', 'honest-analytics' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Entry page', 'honest-analytics' ); ?></th>
							<th scope="col" class="ha-num"><?php esc_html_e( 'Pages', 'honest-analytics' ); ?></th>
							<th scope="col" class="ha-num"><?php esc_html_e( 'Time on site', 'honest-analytics' ); ?></th>
							<th scope="col" class="ha-num"><?php esc_html_e( 'Last seen', 'honest-analytics' ); ?></th>
						</tr>
					</thead>

					<tbody data-ha-sessions>
						<?php if ( [] === $snapshot['active'] ) : ?>
							<tr>
								<td colspan="5" class="ha-muted"><?php esc_html_e( 'Nobody is on the site right now.', 'honest-analytics' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $snapshot['active'] as $ha_session ) : ?>
								<tr>
									<td class="ha-path-cell"><?php echo esc_html( $ha_session['currentPath'] ); ?></td>
									<td class="ha-path-cell ha-muted"><?php echo esc_html( $ha_session['entryPath'] ); ?></td>
									<td class="ha-num"><?php echo esc_html( number_format_i18n( $ha_session['pageviews'] ) ); ?></td>
									<td class="ha-num-muted">
										<?php echo esc_html( $ha_session['isNew'] ? __( 'just arrived', 'honest-analytics' ) : Format::duration( $ha_session['durationMs'] ) ); ?>
									</td>
									<td class="ha-num-muted">
										<?php
										echo esc_html(
											$ha_session['secondsAgo'] < 10
												? __( 'just now', 'honest-analytics' )
												: sprintf(
													/* translators: %s: a length of time, e.g. "2 mins". */
													__( '%s ago', 'honest-analytics' ),
													human_time_diff( time() - $ha_session['secondsAgo'] )
												)
										);
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $snapshot['truncated'] > 0 ) : ?>
				<p class="ha-muted ha-gap-above">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of additional sessions. */
							_n( '%d more not shown.', '%d more not shown.', $snapshot['truncated'], 'honest-analytics' ),
							$snapshot['truncated']
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>
</div>

<p class="ha-footnote is-flush">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %d: session window in minutes. */
			__( 'Read entirely from the session layer, so this screen runs no report queries. Sessions close after %d minutes of inactivity; there is no date range or export here because nothing on this screen is historical.', 'honest-analytics' ),
			(int) round( $sessionWindow / 60 )
		)
	);
	?>
</p>

<p class="ha-footnote">
	<?php esc_html_e( 'A row is a visit, not a person. There is nothing here that identifies anyone: no address, no name, no identifier that outlives the visit. Somebody who leaves and comes back tomorrow is a new row, with no way to know it was them - that is the salt rotation, and it is what keeps this screen cookieless.', 'honest-analytics' ); ?>
</p>
