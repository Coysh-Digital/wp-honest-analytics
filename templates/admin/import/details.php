<?php
/**
 * The detail, for anybody who wants it.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen    $screen
 * @var \HonestAnalytics\Import\ImportJob|null         $job
 * @var \HonestAnalytics\Import\ImporterInterface[]    $importers
 */

declare(strict_types=1);

use HonestAnalytics\Support\Timezone;

use HonestAnalytics\Admin\Views\View;
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

$ha_statuses = [
	ImportJob::STATUS_PENDING   => __( 'Waiting to start', 'honest-analytics' ),
	ImportJob::STATUS_RUNNING   => __( 'In progress', 'honest-analytics' ),
	ImportJob::STATUS_WAITING   => __( 'Paused, carrying on shortly', 'honest-analytics' ),
	ImportJob::STATUS_COMPLETE  => __( 'Complete', 'honest-analytics' ),
	ImportJob::STATUS_FAILED    => __( 'Stopped after a problem', 'honest-analytics' ),
	ImportJob::STATUS_CANCELLED => __( 'Stopped by you', 'honest-analytics' ),
];

$ha_importer = null;

foreach ( $importers as $ha_candidate ) {
	if ( $ha_candidate->id() === $job->source ) {
		$ha_importer = $ha_candidate;

		break;
	}
}

$ha_property = (string) $job->configuration->option( 'propertyName', '' );
?>
<p class="ha-back">
	<a href="<?php echo esc_url( $screen->homeUrl() ); ?>">&larr; <?php esc_html_e( 'Import data', 'honest-analytics' ); ?></a>
</p>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'This import', 'honest-analytics' ); ?></h2>
	</div>

	<div class="ha-card-body">
		<div class="ha-scroll">
			<table class="ha-table">
				<caption class="ha-visually-hidden"><?php esc_html_e( 'Details of this import', 'honest-analytics' ); ?></caption>

				<tbody>
					<tr>
						<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Status', 'honest-analytics' ); ?></th>
						<td><?php echo esc_html( $ha_statuses[ $job->status ] ?? $job->status ); ?></td>
					</tr>

					<tr>
						<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Source', 'honest-analytics' ); ?></th>
						<td><?php echo esc_html( ImportSource::label( $job->source ) ); ?></td>
					</tr>

					<?php if ( '' !== $ha_property ) : ?>
						<tr>
							<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Property', 'honest-analytics' ); ?></th>
							<td><?php echo esc_html( $ha_property ); ?></td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Dates imported', 'honest-analytics' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: start date, 2: end date. */
									__( '%1$s to %2$s', 'honest-analytics' ),
									wp_date( 'j M Y', (int) Timezone::middayOn( $job->configuration->dateFrom ) ),
									wp_date( 'j M Y', (int) Timezone::middayOn( $job->configuration->dateTo ) )
								)
							);
							?>
						</td>
					</tr>

					<tr>
						<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Records imported', 'honest-analytics' ); ?></th>
						<td class="ha-nowrap-num"><?php echo esc_html( Format::count( $job->recordsImported ) ); ?></td>
					</tr>

					<?php if ( $job->recordsSkipped > 0 ) : ?>
						<tr>
							<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Records skipped', 'honest-analytics' ); ?></th>
							<td>
								<span class="ha-nowrap-num"><?php echo esc_html( Format::count( $job->recordsSkipped ) ); ?></span>
								<p class="ha-muted ha-fine ha-tight"><?php esc_html_e( 'Those dates were already covered by another import.', 'honest-analytics' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( $job->completedAt > 0 ) : ?>
						<tr>
							<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Finished', 'honest-analytics' ); ?></th>
							<td><?php echo esc_html( Timezone::dateTime( $job->completedAt ) ); ?></td>
						</tr>
					<?php elseif ( $job->startedAt > 0 ) : ?>
						<tr>
							<th scope="row" class="ha-rowhead"><?php esc_html_e( 'Started', 'honest-analytics' ); ?></th>
							<td><?php echo esc_html( Timezone::dateTime( $job->startedAt ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $job->isActive() ) : ?>
			<p class="ha-gap-above">
				<a class="button" href="<?php echo esc_url( $screen->jobUrl( 'progress', $job->id ) ); ?>">
					<?php esc_html_e( 'View progress', 'honest-analytics' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</div>

<?php if ( null !== $ha_importer ) : ?>
	<?php $ha_summary = $ha_importer->inspect(); ?>

	<?php if ( [] !== $ha_summary->dimensions ) : ?>
		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'What was brought across', 'honest-analytics' ); ?></h2>
			</div>

			<div class="ha-card-body">
				<ul class="ha-ticks">
					<?php foreach ( $ha_summary->dimensions as $ha_dimension ) : ?>
						<li><span class="ha-tick" aria-hidden="true">&#10003;</span><?php echo esc_html( $ha_dimension ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( [] !== $ha_importer->mappings() ) : ?>
		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'How the numbers map', 'honest-analytics' ); ?></h2>
			</div>

			<div class="ha-card-body">
				<?php View::render( 'admin/import/partials/mappings', [ 'mappings' => $ha_importer->mappings() ] ); ?>
			</div>
		</div>
	<?php endif; ?>
<?php endif; ?>

<div class="ha-info">
	<p>
		<?php esc_html_e( 'Imported analytics sit alongside the ones this plugin measures, marked internally so the two can always be told apart. Removing the tool you imported from will not remove this history.', 'honest-analytics' ); ?>
	</p>
</div>
