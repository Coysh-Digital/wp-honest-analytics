<?php
/**
 * The dashboard overview widget.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format one metric the way its own units want.
 *
 * @param string          $key    Metric key.
 * @param int|float       $value  Value.
 * @param bool            $beacon Whether the beacon is in use.
 */
$ha_format = static function ( string $key, int|float $value, bool $beacon ): string {
	return match ( $key ) {
		'bounceRate'         => Format::percent( (float) $value, 0 ),
		'avgViewsPerSession' => number_format_i18n( (float) $value, 1 ),
		'avgDurationMs'      => $value > 0 ? Format::duration( (int) $value ) : '-',
		'avgDwellMs'         => $beacon && $value > 0 ? Format::duration( (int) $value ) : '-',
		default              => Format::compact( $value ),
	};
};
?>
<div class="ha-widget-stats">
	<?php foreach ( $metrics as $ha_metric ) : ?>
		<div class="ha-widget-stat">
			<div class="ha-widget-label"><?php echo esc_html( $labels[ $ha_metric ] ?? $ha_metric ); ?></div>
			<div class="ha-widget-value"><?php echo esc_html( $ha_format( $ha_metric, $totals[ $ha_metric ] ?? 0, $beacon ) ); ?></div>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( '' !== $sparkline ) : ?>
	<svg class="ha-sparkline" viewBox="0 0 300 40" preserveAspectRatio="none" role="img"
		aria-label="<?php esc_attr_e( 'Daily pageviews over the selected period.', 'honest-analytics' ); ?>">
		<path d="<?php echo esc_attr( $sparkline ); ?>" />
	</svg>
<?php endif; ?>

<div class="ha-widget-foot">
	<span><?php echo esc_html( $range->label ); ?></span>
	<a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open analytics', 'honest-analytics' ); ?></a>
</div>

<?php if ( in_array( 'uniques', $metrics, true ) ) : ?>
	<p class="ha-muted ha-widget-fine">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: accuracy, e.g. "±1.6%". */
				__( 'Visitors are daily unique estimates (%s): the hashing salt is destroyed every 24 hours, so somebody returning on three days counts three times.', 'honest-analytics' ),
				$accuracy
			)
		);
		?>
	</p>
<?php endif; ?>
