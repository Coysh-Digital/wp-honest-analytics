<?php
/**
 * The bar.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen  $screen
 * @var \HonestAnalytics\Import\ImportJob|null       $job
 */

declare(strict_types=1);

use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( null === $job ) {
	?>
	<div class="ha-card">
		<div class="ha-card-body">
			<p><?php esc_html_e( 'That import could not be found. It may have finished, or it may have been started on another site.', 'honest-analytics' ); ?></p>

			<p class="ha-gap-above">
				<a class="button" href="<?php echo esc_url( $screen->homeUrl() ); ?>">
					<?php esc_html_e( 'Back to importing', 'honest-analytics' ); ?>
				</a>
			</p>
		</div>
	</div>
	<?php

	return;
}

if ( $job->isComplete() ) {
	wp_safe_redirect(
		$screen->url(
			[
				'step'   => 'complete',
				'job'    => (string) $job->id,
				'source' => null,
			]
		)
	);

	exit;
}

$ha_percent = $job->percent();
?>
<div class="ha-card" data-ha-import>
	<div class="ha-card-head">
		<h2 class="ha-card-title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the name of the analytics tool being imported from. */
					__( 'Importing %s', 'honest-analytics' ),
					ImportSource::label( $job->source )
				)
			);
			?>
		</h2>
	</div>

	<div class="ha-card-body">
		<div
			class="ha-progress"
			role="progressbar"
			aria-valuemin="0"
			aria-valuemax="100"
			aria-valuenow="<?php echo esc_attr( (string) $ha_percent ); ?>"
			aria-label="<?php esc_attr_e( 'Import progress', 'honest-analytics' ); ?>"
			data-ha-progress
		>
			<div class="ha-progress-fill" style="width:<?php echo esc_attr( (string) $ha_percent ); ?>%"></div>
		</div>

		<p class="ha-progress-figures">
			<strong data-ha-percent><?php echo esc_html( $ha_percent . '%' ); ?></strong>
			<span class="ha-muted" data-ha-processed>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: a number of records, already formatted. */
						__( '%s records processed', 'honest-analytics' ),
						Format::count( $job->recordsProcessed )
					)
				);
				?>
			</span>
		</p>

		<p class="ha-muted" aria-live="polite" data-ha-import-status>
			<?php esc_html_e( 'You can leave this page. The import will continue in the background.', 'honest-analytics' ); ?>
		</p>

		<form method="post" class="ha-gap-above">
			<?php wp_nonce_field( 'honest-analytics-import' ); ?>
			<input type="hidden" name="honest_import_action" value="cancel" />
			<input type="hidden" name="job" value="<?php echo esc_attr( (string) $job->id ); ?>" />

			<a class="button" href="<?php echo esc_url( $screen->homeUrl() ); ?>">
				<?php esc_html_e( 'Leave it running', 'honest-analytics' ); ?>
			</a>

			<button
				type="submit"
				class="button-link ha-wizard-cancel"
				data-ha-confirm="<?php esc_attr_e( 'Stop this import? Everything it has already brought across stays, and you can pick up where it left off later.', 'honest-analytics' ); ?>"
			>
				<?php esc_html_e( 'Stop importing', 'honest-analytics' ); ?>
			</button>
		</form>
	</div>
</div>

<p class="ha-footnote">
	<?php esc_html_e( 'Large imports are done in small pieces so they never hold up your site. If your browser closes, the server keeps going and picks up exactly where it stopped.', 'honest-analytics' ); ?>
</p>
