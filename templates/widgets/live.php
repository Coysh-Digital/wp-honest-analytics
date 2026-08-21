<?php
/**
 * The dashboard real-time widget.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div data-ha-realtime>
	<div class="ha-widget-stats">
		<div class="ha-widget-stat">
			<div class="ha-widget-label">
				<span class="ha-live-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Visitors now', 'honest-analytics' ); ?>
			</div>
			<div class="ha-widget-hero" data-ha-count="visitors"><?php echo esc_html( number_format_i18n( $snapshot['visitors'] ) ); ?></div>
		</div>

		<div class="ha-widget-stat">
			<div class="ha-widget-label"><?php esc_html_e( 'Pages viewed', 'honest-analytics' ); ?></div>
			<div class="ha-widget-hero" data-ha-count="pageviews"><?php echo esc_html( number_format_i18n( $snapshot['pageviews'] ) ); ?></div>
		</div>
	</div>

	<table class="ha-widget-table">
		<tbody data-ha-sessions>
			<?php if ( [] === $snapshot['pages'] ) : ?>
				<tr><td class="ha-muted"><?php esc_html_e( 'Nobody is on the site right now.', 'honest-analytics' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $snapshot['pages'] as $ha_page ) : ?>
					<tr>
						<td class="ha-path-mono"><?php echo esc_html( $ha_page['path'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $ha_page['visitors'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<div class="ha-widget-foot">
	<span data-ha-updated><?php esc_html_e( 'Updated just now', 'honest-analytics' ); ?></span>
	<a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Real-time →', 'honest-analytics' ); ?></a>
</div>
