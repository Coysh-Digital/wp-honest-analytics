<?php
/**
 * One settings row: label, control, help, and its consequence.
 *
 * Every consequential setting states what changes if you change it. A settings
 * screen that only names its switches leaves somebody to discover the
 * consequence afterwards, which for half of these is a privacy posture rather
 * than a preference.
 *
 * @package HonestAnalytics
 *
 * @var string      $label
 * @var string      $control  Rendered HTML for the control.
 * @var string      $help
 * @var string      $consequence
 * @var string|null $override
 * @var string      $for
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_for = $for ?? '';
?>
<div class="ha-setting">
	<?php if ( '' !== $ha_for ) : ?>
		<label for="<?php echo esc_attr( $ha_for ); ?>"><?php echo esc_html( $label ); ?></label>
	<?php else : ?>
		<span class="ha-setting-label"><?php echo esc_html( $label ); ?></span>
	<?php endif; ?>

	<div>
		<div class="ha-setting-control">
			<?php
			// The control is assembled by the settings template from escaped
			// pieces; there is no user input in it.
			echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>

		<p class="ha-setting-help"><?php echo esc_html( $help ); ?></p>

		<?php if ( '' !== ( $consequence ?? '' ) ) : ?>
			<p class="ha-consequence">
				<strong><?php esc_html_e( 'Consequence:', 'honest-analytics' ); ?></strong>
				<?php echo esc_html( $consequence ); ?>
			</p>
		<?php endif; ?>

		<?php if ( null !== ( $override ?? null ) ) : ?>
			<p class="ha-overridden">
				<?php
				echo esc_html(
					'constant' === $override
						? __( 'Set in wp-config.php, so it cannot be changed here.', 'honest-analytics' )
						: __( 'Set by a filter in your theme or another plugin, so it cannot be changed here.', 'honest-analytics' )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</div>
