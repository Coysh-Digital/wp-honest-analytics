<?php
/**
 * Step three, and only when it applies: some of these dates are already covered.
 *
 * Two analytics tools measuring the same week and both being imported would
 * add together, which would quietly double a month of somebody's history. So
 * this asks, and the safe answer is the one already selected.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen  $screen
 * @var \HonestAnalytics\Import\ImporterInterface    $importer
 */

declare(strict_types=1);

use HonestAnalytics\Support\Timezone;

use HonestAnalytics\Import\ImportConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_chosen   = $screen->requestedRange( $importer );
$ha_overlaps = $screen->overlapping( $importer->id(), $ha_chosen['from'], $ha_chosen['to'] );
?>
<p class="ha-back">
	<a href="<?php echo esc_url( $screen->url( [ 'step' => 'range' ] ) ); ?>">&larr; <?php esc_html_e( 'Back', 'honest-analytics' ); ?></a>
</p>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'Some of these dates are already covered', 'honest-analytics' ); ?></h2>
	</div>

	<div class="ha-card-body">
		<p class="ha-measure">
			<?php esc_html_e( 'You have already imported analytics that overlap the dates you have chosen. Importing both without deciding what to do would count the same days twice.', 'honest-analytics' ); ?>
		</p>

		<div class="ha-scroll">
			<table class="ha-table">
				<caption class="ha-visually-hidden"><?php esc_html_e( 'Dates already imported, by source', 'honest-analytics' ); ?></caption>

				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Already imported from', 'honest-analytics' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Covering', 'honest-analytics' ); ?></th>
						<th scope="col" class="ha-num"><?php esc_html_e( 'Days', 'honest-analytics' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<?php foreach ( $ha_overlaps as $ha_source => $ha_span ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $screen->sourceLabel( (string) $ha_source ) ); ?></th>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: start date, 2: end date. */
										__( '%1$s to %2$s', 'honest-analytics' ),
										wp_date( 'j M Y', (int) Timezone::middayOn( $ha_span['from'] ) ),
										wp_date( 'j M Y', (int) Timezone::middayOn( $ha_span['to'] ) )
									)
								);
								?>
							</td>
							<td class="ha-num"><?php echo esc_html( number_format_i18n( $ha_span['days'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ha-choices ha-gap-above">
			<input type="hidden" name="page" value="<?php echo esc_attr( $screen->slug() ); ?>" />
			<input type="hidden" name="source" value="<?php echo esc_attr( $importer->id() ); ?>" />
			<input type="hidden" name="step" value="preview" />
			<input type="hidden" name="range" value="<?php echo esc_attr( $ha_chosen['choice'] ); ?>" />
			<input type="hidden" name="from" value="<?php echo esc_attr( $ha_chosen['from'] ); ?>" />
			<input type="hidden" name="to" value="<?php echo esc_attr( $ha_chosen['to'] ); ?>" />

			<label class="ha-choice">
				<input type="radio" name="overlap" value="<?php echo esc_attr( ImportConfiguration::OVERLAP_SKIP ); ?>" checked="checked" />
				<span>
					<span class="ha-choice-title"><?php esc_html_e( 'Import only the dates that aren’t already covered', 'honest-analytics' ); ?></span>
					<span class="ha-choice-note"><?php esc_html_e( 'Nothing you already have is touched, and nothing is counted twice.', 'honest-analytics' ); ?></span>
				</span>
			</label>

			<label class="ha-choice">
				<input type="radio" name="overlap" value="<?php echo esc_attr( ImportConfiguration::OVERLAP_REPLACE ); ?>" />
				<span>
					<span class="ha-choice-title"><?php esc_html_e( 'Replace the existing imported data for those dates', 'honest-analytics' ); ?></span>
					<span class="ha-choice-note"><?php esc_html_e( 'The other import’s figures for the overlapping days are removed and these ones take their place. Analytics measured by this plugin are never affected.', 'honest-analytics' ); ?></span>
				</span>
			</label>

			<div class="ha-wizard-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'honest-analytics' ); ?></button>

				<a class="ha-wizard-cancel" href="<?php echo esc_url( $screen->homeUrl() ); ?>">
					<?php esc_html_e( 'Cancel', 'honest-analytics' ); ?>
				</a>
			</div>
		</form>
	</div>
</div>
