<?php
/**
 * The analytics panel in the post editor.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ha-metabox">
	<div class="ha-widget-stats">
		<div class="ha-widget-stat">
			<div class="ha-widget-label"><?php esc_html_e( 'Views', 'honest-analytics' ); ?></div>
			<div class="ha-widget-value"><?php echo esc_html( Format::compact( $stats['views'] ) ); ?></div>
		</div>

		<div class="ha-widget-stat">
			<div class="ha-widget-label"><?php esc_html_e( 'Visitors', 'honest-analytics' ); ?></div>
			<div class="ha-widget-value"><?php echo esc_html( Format::compact( $stats['uniques'] ) ); ?></div>
		</div>
	</div>

	<?php if ( '' !== $sparkline ) : ?>
		<svg class="ha-sparkline" viewBox="0 0 200 30" preserveAspectRatio="none" role="img"
			aria-label="<?php esc_attr_e( 'Daily views for this post over the last 30 days.', 'honest-analytics' ); ?>">
			<path d="<?php echo esc_attr( $sparkline ); ?>" />
		</svg>
	<?php endif; ?>

	<p class="ha-widget-note">
		<a href="<?php echo esc_url( $detailUrl ); ?>"><?php esc_html_e( 'Full report for this page', 'honest-analytics' ); ?></a>
	</p>

	<p class="ha-muted ha-widget-fine">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: accuracy, e.g. "±1.6%". */
				__( 'Visitors are daily unique estimates (%s).', 'honest-analytics' ),
				$accuracy
			)
		);
		?>
	</p>
</div>
