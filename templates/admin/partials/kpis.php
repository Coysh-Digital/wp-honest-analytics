<?php
/**
 * The connected strip of headline figures.
 *
 * @package HonestAnalytics
 *
 * @var array<int,array<string,mixed>> $kpis
 */

declare(strict_types=1);

use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ha-kpis">
	<?php foreach ( $kpis as $ha_kpi ) : ?>
		<?php
		$ha_delta = $ha_kpi['delta'] ?? null;
		$ha_class = 'is-flat';

		if ( null !== $ha_delta ) {
			$ha_good  = ! empty( $ha_kpi['inverse'] ) ? $ha_delta < 0 : $ha_delta > 0;
			$ha_class = abs( $ha_delta ) < 0.05 ? 'is-flat' : ( $ha_good ? 'is-up' : 'is-down' );
		}
		?>
		<div class="ha-kpi">
			<div class="ha-kpi-label">
				<?php echo esc_html( $ha_kpi['label'] ); ?>
			</div>

			<div class="ha-kpi-value"><?php echo esc_html( $ha_kpi['value'] ); ?></div>

			<div class="ha-kpi-sub">
				<?php if ( null !== $ha_delta ) : ?>
					<span class="ha-delta <?php echo esc_attr( $ha_class ); ?>"><?php echo esc_html( Format::delta( (float) $ha_delta ) ); ?></span>
				<?php endif; ?>
				<?php echo esc_html( $ha_kpi['note'] ?? '' ); ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
