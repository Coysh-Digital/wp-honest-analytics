<?php
/**
 * The overview widget's settings form.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p>
	<label for="ha-widget-range"><?php esc_html_e( 'Period', 'honest-analytics' ); ?></label><br />
	<select id="ha-widget-range" name="honest_analytics_overview[range]">
		<?php foreach ( $presets as $ha_key => $ha_label ) : ?>
			<option value="<?php echo esc_attr( $ha_key ); ?>" <?php selected( $preferences['range'], $ha_key ); ?>>
				<?php echo esc_html( $ha_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</p>

<fieldset>
	<legend><?php esc_html_e( 'Show', 'honest-analytics' ); ?></legend>

	<?php foreach ( $metrics as $ha_key => $ha_label ) : ?>
		<label class="ha-check-row">
			<input
				type="checkbox"
				name="honest_analytics_overview[metrics][]"
				value="<?php echo esc_attr( $ha_key ); ?>"
				<?php checked( in_array( $ha_key, $preferences['metrics'], true ) ); ?>
			/>
			<?php echo esc_html( $ha_label ); ?>
		</label>
	<?php endforeach; ?>
</fieldset>

<p class="ha-muted"><?php esc_html_e( 'These choices are yours alone; other people on this site keep their own.', 'honest-analytics' ); ?></p>
