<?php
/**
 * Step two: how much history to bring across.
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

$ha_range = $screen->availableRange( $importer );
$ha_from  = $ha_range['from'];
$ha_to    = $ha_range['to'];

// A year back from the end of what exists, not from today: a source that
// stopped collecting eighteen months ago should still offer something useful.
$ha_year = wp_date( 'Y-m-d', (int) strtotime( '-12 months', (int) strtotime( $ha_to ) ) );
$ha_year = max( $ha_year, $ha_from );

// The overlap step only appears when there is an overlap to resolve.
$ha_next = [] !== $screen->overlapping( $importer->id(), $ha_from, $ha_to ) ? 'overlap' : 'preview';
?>
<p class="ha-back">
	<a href="<?php echo esc_url( $screen->url( [ 'step' => 'notes' ] ) ); ?>">&larr; <?php esc_html_e( 'Back', 'honest-analytics' ); ?></a>
</p>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'What would you like to import?', 'honest-analytics' ); ?></h2>
	</div>

	<div class="ha-card-body">
		<p class="ha-muted ha-tight-below">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: start date, 2: end date. */
					__( 'We found analytics from %1$s to %2$s.', 'honest-analytics' ),
					wp_date( 'j F Y', (int) strtotime( $ha_from ) ),
					wp_date( 'j F Y', (int) strtotime( $ha_to ) )
				)
			);
			?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ha-choices">
			<input type="hidden" name="page" value="<?php echo esc_attr( $screen->slug() ); ?>" />
			<input type="hidden" name="source" value="<?php echo esc_attr( $importer->id() ); ?>" />
			<input type="hidden" name="step" value="<?php echo esc_attr( $ha_next ); ?>" />

			<label class="ha-choice">
				<input type="radio" name="range" value="all" checked="checked" />
				<span>
					<span class="ha-choice-title"><?php esc_html_e( 'All available history', 'honest-analytics' ); ?></span>
					<span class="ha-choice-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: start date, 2: end date. */
								__( '%1$s to %2$s', 'honest-analytics' ),
								wp_date( 'j M Y', (int) strtotime( $ha_from ) ),
								wp_date( 'j M Y', (int) strtotime( $ha_to ) )
							)
						);
						?>
					</span>
				</span>
			</label>

			<label class="ha-choice">
				<input type="radio" name="range" value="year" />
				<span>
					<span class="ha-choice-title"><?php esc_html_e( 'The last 12 months', 'honest-analytics' ); ?></span>
					<span class="ha-choice-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: start date, 2: end date. */
								__( '%1$s to %2$s', 'honest-analytics' ),
								wp_date( 'j M Y', (int) strtotime( $ha_year ) ),
								wp_date( 'j M Y', (int) strtotime( $ha_to ) )
							)
						);
						?>
					</span>
				</span>
			</label>

			<label class="ha-choice">
				<input type="radio" name="range" value="custom" />
				<span>
					<span class="ha-choice-title"><?php esc_html_e( 'Choose dates', 'honest-analytics' ); ?></span>
				</span>
			</label>

			<div class="ha-form-row ha-choice-dates">
				<label class="ha-field" for="honest-import-from">
					<?php esc_html_e( 'From', 'honest-analytics' ); ?>
					<input
						type="date"
						id="honest-import-from"
						name="from"
						value="<?php echo esc_attr( $ha_from ); ?>"
						min="<?php echo esc_attr( $ha_from ); ?>"
						max="<?php echo esc_attr( $ha_to ); ?>"
					/>
				</label>

				<label class="ha-field" for="honest-import-to">
					<?php esc_html_e( 'To', 'honest-analytics' ); ?>
					<input
						type="date"
						id="honest-import-to"
						name="to"
						value="<?php echo esc_attr( $ha_to ); ?>"
						min="<?php echo esc_attr( $ha_from ); ?>"
						max="<?php echo esc_attr( $ha_to ); ?>"
					/>
				</label>
			</div>

			<div class="ha-wizard-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'honest-analytics' ); ?></button>

				<a class="ha-wizard-cancel" href="<?php echo esc_url( $screen->homeUrl() ); ?>">
					<?php esc_html_e( 'Not now', 'honest-analytics' ); ?>
				</a>
			</div>
		</form>
	</div>
</div>

<p class="ha-footnote">
	<?php esc_html_e( 'Older history is subject to the same retention window as everything else: anything past the limit on your Settings screen is removed at the next tidy-up, whether it was imported or measured here.', 'honest-analytics' ); ?>
</p>
