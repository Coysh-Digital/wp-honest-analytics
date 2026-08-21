<?php
/**
 * A chart, its data, its hidden table and its fallback.
 *
 * Four things ship together and none of them is optional. The canvas is what
 * most people see. The JSON block is read by the charting code - a block rather
 * than an inline script, because optimisers rewrite and defer inline scripts on
 * authenticated screens and leave JSON alone. The table is the same numbers,
 * hidden but present, so a screen reader has something to read and so the
 * figures survive the chart failing. And the fallback note explains what
 * happened when it does.
 *
 * @package HonestAnalytics
 *
 * @var string               $id
 * @var array<string,mixed>  $payload
 * @var string               $label
 * @var string               $caption
 * @var string               $classes
 * @var bool                 $table
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_id      = $id ?? 'ha-chart';
$ha_classes = $classes ?? 'ha-chart';
$ha_label   = $label ?? '';
$ha_table   = ! isset( $table ) || $table;
$ha_full    = $payload['full'] ?? $payload['labels'];
?>
<figure class="<?php echo esc_attr( $ha_classes ); ?>" data-ha-chart="<?php echo esc_attr( $ha_id ); ?>">
	<canvas id="<?php echo esc_attr( $ha_id ); ?>" role="img" aria-label="<?php echo esc_attr( $ha_label ); ?>"></canvas>

	<script type="application/json" id="<?php echo esc_attr( $ha_id ); ?>-data">
		<?php echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?>
	</script>
</figure>

<p class="ha-fallback" id="<?php echo esc_attr( $ha_id ); ?>-fallback" hidden>
	<?php esc_html_e( 'This chart could not be drawn, so the figures behind it are shown instead.', 'honest-analytics' ); ?>
</p>

<?php if ( $ha_table ) : ?>
	<div class="ha-visually-hidden" id="<?php echo esc_attr( $ha_id ); ?>-table">
		<table class="ha-table">
			<?php if ( '' !== ( $caption ?? '' ) ) : ?>
				<caption><?php echo esc_html( $caption ); ?></caption>
			<?php endif; ?>

			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $payload['options']['labelHeading'] ?? __( 'Date', 'honest-analytics' ) ); ?></th>
					<?php foreach ( $payload['datasets'] as $ha_dataset ) : ?>
						<th scope="col"><?php echo esc_html( $ha_dataset['label'] ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>

			<tbody>
				<?php foreach ( $payload['labels'] as $ha_index => $ha_point ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $ha_full[ $ha_index ] ?? $ha_point ); ?></th>
						<?php foreach ( $payload['datasets'] as $ha_dataset ) : ?>
							<td><?php echo esc_html( number_format_i18n( (float) ( $ha_dataset['data'][ $ha_index ] ?? 0 ) ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
