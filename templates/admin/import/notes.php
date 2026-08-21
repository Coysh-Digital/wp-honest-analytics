<?php
/**
 * Step one: what will change about your numbers.
 *
 * Shown before every import, and before anything is chosen, because a person
 * who finds out afterwards that their visitor figures moved has been treated
 * badly by whoever built the wizard.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen  $screen
 * @var \HonestAnalytics\Import\ImporterInterface    $importer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_notes = $importer->notes();
?>
<p class="ha-back">
	<a href="<?php echo esc_url( $screen->homeUrl() ); ?>">&larr; <?php esc_html_e( 'Import data', 'honest-analytics' ); ?></a>
</p>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'A quick note about your numbers', 'honest-analytics' ); ?></h2>
	</div>

	<div class="ha-card-body">
		<p class="ha-measure">
			<?php esc_html_e( 'Analytics platforms don’t all count visitors, sessions and page views in exactly the same way.', 'honest-analytics' ); ?>
		</p>

		<p class="ha-measure">
			<?php esc_html_e( 'We’ll import your historical data so you can keep useful trends and comparisons, but you may notice a change in numbers around the date you switched to this plugin.', 'honest-analytics' ); ?>
		</p>

		<p class="ha-measure">
			<strong><?php esc_html_e( 'Nothing is wrong - the measurement method has changed.', 'honest-analytics' ); ?></strong>
		</p>
	</div>
</div>

<?php if ( [] !== $ha_notes ) : ?>
	<div class="ha-card">
		<div class="ha-card-head">
			<h2 class="ha-card-title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the name of the analytics tool being imported from. */
						__( 'About %s data', 'honest-analytics' ),
						$importer->name()
					)
				);
				?>
			</h2>
		</div>

		<div class="ha-card-body">
			<?php foreach ( $ha_notes as $ha_note ) : ?>
				<p class="ha-measure"><?php echo esc_html( $ha_note ); ?></p>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

<div class="ha-wizard-actions">
	<a class="button button-primary" href="<?php echo esc_url( $screen->url( [ 'step' => 'range' ] ) ); ?>">
		<?php esc_html_e( 'Continue', 'honest-analytics' ); ?>
	</a>

	<a class="ha-wizard-cancel" href="<?php echo esc_url( $screen->homeUrl() ); ?>">
		<?php esc_html_e( 'Not now', 'honest-analytics' ); ?>
	</a>
</div>
