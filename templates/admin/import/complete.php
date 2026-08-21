<?php
/**
 * What came across.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen  $screen
 * @var \HonestAnalytics\Import\ImportJob|null       $job
 */

declare(strict_types=1);

use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( null === $job ) {
	?>
	<div class="ha-card">
		<div class="ha-card-body">
			<p><?php esc_html_e( 'That import could not be found.', 'honest-analytics' ); ?></p>

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

$ha_name = ImportSource::label( $job->source );
$ha_from = (int) strtotime( $job->configuration->dateFrom );
$ha_to   = (int) strtotime( $job->configuration->dateTo );
$ha_days = max( 1, (int) floor( ( $ha_to - $ha_from ) / DAY_IN_SECONDS ) + 1 );

$ha_years  = (int) floor( $ha_days / 365 );
$ha_months = (int) floor( ( $ha_days % 365 ) / 30 );

if ( $ha_years > 0 && $ha_months > 0 ) {
	$ha_span = sprintf(
		/* translators: 1: a number of years, 2: a number of months. */
		_n( '%1$d year, %2$d months', '%1$d years, %2$d months', $ha_years, 'honest-analytics' ),
		$ha_years,
		$ha_months
	);
} elseif ( $ha_years > 0 ) {
	/* translators: %d: a number of years. */
	$ha_span = sprintf( _n( '%d year', '%d years', $ha_years, 'honest-analytics' ), $ha_years );
} else {
	/* translators: %d: a number of days. */
	$ha_span = sprintf( _n( '%d day', '%d days', $ha_days, 'honest-analytics' ), $ha_days );
}
?>
<div class="ha-card">
	<div class="ha-card-body">
		<div class="ha-posture <?php echo ImportJob::STATUS_COMPLETE === $job->status ? 'is-clean' : 'is-consent'; ?>">
			<span class="ha-posture-icon" aria-hidden="true"><?php echo ImportJob::STATUS_COMPLETE === $job->status ? '&#10003;' : '!'; ?></span>

			<div>
				<div class="ha-posture-label"><?php esc_html_e( 'Import', 'honest-analytics' ); ?></div>

				<div class="ha-posture-title">
					<?php if ( ImportJob::STATUS_COMPLETE === $job->status ) : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the name of the analytics tool imported from. */
								__( 'Your %s history has been imported.', 'honest-analytics' ),
								$ha_name
							)
						);
						?>
					<?php elseif ( ImportJob::STATUS_CANCELLED === $job->status ) : ?>
						<?php esc_html_e( 'This import was stopped.', 'honest-analytics' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'This import did not finish.', 'honest-analytics' ); ?>
					<?php endif; ?>
				</div>

				<p class="ha-posture-body">
					<?php if ( ImportJob::STATUS_COMPLETE !== $job->status ) : ?>
						<?php esc_html_e( 'Everything it did bring across is already in your reports, and starting it again will carry on rather than repeat what is done.', 'honest-analytics' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'It is already part of your analytics dashboard.', 'honest-analytics' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>
	</div>
</div>

<div class="ha-card">
	<div class="ha-card-body">
		<ul class="ha-plain-list ha-outcome">
			<li>
				<strong class="ha-outcome-figure"><?php echo esc_html( Format::count( $job->recordsImported ) ); ?></strong>
				<span class="ha-muted"><?php esc_html_e( 'records imported', 'honest-analytics' ); ?></span>
			</li>

			<?php if ( $job->recordsSkipped > 0 ) : ?>
				<li>
					<strong class="ha-outcome-figure"><?php echo esc_html( Format::count( $job->recordsSkipped ) ); ?></strong>
					<span class="ha-muted"><?php esc_html_e( 'records skipped, because those dates were already covered', 'honest-analytics' ); ?></span>
				</li>
			<?php endif; ?>

			<li>
				<strong class="ha-outcome-figure"><?php echo esc_html( $ha_span ); ?></strong>
				<span class="ha-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start date, 2: end date. */
							__( 'of history, %1$s to %2$s', 'honest-analytics' ),
							wp_date( 'j M Y', $ha_from ),
							wp_date( 'j M Y', $ha_to )
						)
					);
					?>
				</span>
			</li>
		</ul>

		<div class="ha-info ha-gap-above">
			<p>
				<?php esc_html_e( 'Remember: visitor and session numbers may change slightly from today, because this plugin measures traffic differently from the tool you imported. Your historical trends are still comparable - the exact totals are not.', 'honest-analytics' ); ?>
			</p>
		</div>

		<div class="ha-wizard-actions">
			<a class="button button-primary" href="<?php echo esc_url( menu_page_url( 'honest-analytics', false ) ); ?>">
				<?php esc_html_e( 'View analytics', 'honest-analytics' ); ?>
			</a>

			<a href="<?php echo esc_url( $screen->jobUrl( 'details', $job->id ) ); ?>">
				<?php esc_html_e( 'View import details', 'honest-analytics' ); ?>
			</a>
		</div>
	</div>
</div>
