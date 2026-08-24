<?php
/**
 * The last step before anything is written: what was found.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen  $screen
 * @var \HonestAnalytics\Import\ImporterInterface    $importer
 * @var bool                                          $available
 */

declare(strict_types=1);

use HonestAnalytics\Support\Timezone;

use HonestAnalytics\Import\ImportConfiguration;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_chosen   = $screen->requestedRange( $importer );
$ha_detected = $importer->detect();
$ha_summary  = $importer->inspect();
$ha_back     = [] !== $screen->overlapping( $importer->id(), $ha_chosen['from'], $ha_chosen['to'] ) ? 'overlap' : 'range';
$ha_ready    = $available && $ha_detected->isAvailable();
?>
<p class="ha-back">
	<a href="<?php echo esc_url( $screen->url( array_merge( $ha_chosen, [ 'step' => $ha_back ] ) ) ); ?>">&larr; <?php esc_html_e( 'Back', 'honest-analytics' ); ?></a>
</p>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the name of the analytics tool being imported from. */
					__( 'Ready to import %s', 'honest-analytics' ),
					$importer->name()
				)
			);
			?>
		</h2>
	</div>

	<div class="ha-card-body">
		<dl class="ha-stack">
			<div>
				<dt><?php esc_html_e( 'Dates to import', 'honest-analytics' ); ?></dt>
				<dd class="ha-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start date, 2: end date. */
							__( '%1$s to %2$s', 'honest-analytics' ),
							Timezone::date( (int) Timezone::middayOn( $ha_chosen['from'] ) ),
							Timezone::date( (int) Timezone::middayOn( $ha_chosen['to'] ) )
						)
					);
					?>
				</dd>
			</div>

			<?php if ( [] !== $ha_summary->totals ) : ?>
				<div>
					<dt>
						<?php
						echo esc_html(
							$ha_summary->approximate
								? __( 'Approximately', 'honest-analytics' )
								: __( 'Found', 'honest-analytics' )
						);
						?>
					</dt>
					<dd class="ha-muted">
						<ul class="ha-plain-list">
							<?php foreach ( $ha_summary->totals as $ha_total ) : ?>
								<li>
									<strong class="ha-nowrap-num"><?php echo esc_html( Format::count( (int) $ha_total['count'] ) ); ?></strong>
									<?php echo esc_html( (string) $ha_total['label'] ); ?>
								</li>
							<?php endforeach; ?>
						</ul>

						<?php if ( $ha_summary->approximate ) : ?>
							<p class="ha-fine ha-tight">
								<?php esc_html_e( 'These are estimates. Counting every row exactly would have kept you waiting for a number you only need the shape of.', 'honest-analytics' ); ?>
							</p>
						<?php endif; ?>
					</dd>
				</div>
			<?php endif; ?>
		</dl>

		<?php if ( [] !== $ha_summary->dimensions ) : ?>
			<h3 class="ha-subhead"><?php esc_html_e( 'We’ll import', 'honest-analytics' ); ?></h3>

			<ul class="ha-ticks">
				<?php foreach ( $ha_summary->dimensions as $ha_dimension ) : ?>
					<li><span class="ha-tick" aria-hidden="true">&#10003;</span><?php echo esc_html( $ha_dimension ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( [] !== $ha_summary->excluded ) : ?>
			<h3 class="ha-subhead"><?php esc_html_e( 'We won’t import', 'honest-analytics' ); ?></h3>

			<ul class="ha-ticks is-muted">
				<?php foreach ( $ha_summary->excluded as $ha_excluded ) : ?>
					<li><span class="ha-tick" aria-hidden="true">&minus;</span><?php echo esc_html( $ha_excluded ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( [] !== $ha_summary->notes ) : ?>
			<div class="ha-notes">
				<?php foreach ( $ha_summary->notes as $ha_note ) : ?>
					<p class="ha-muted ha-measure"><?php echo esc_html( $ha_note ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ImportConfiguration::OVERLAP_REPLACE === $ha_chosen['overlap'] ) : ?>
			<div class="ha-info is-warning">
				<p><?php esc_html_e( 'Where these dates overlap another import, that import’s figures will be replaced. Analytics measured by this plugin are not affected.', 'honest-analytics' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="ha-info">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the name of the analytics tool being imported from. */
						__( 'We won’t change or remove any %s data. Importing only reads.', 'honest-analytics' ),
						$importer->name()
					)
				);
				?>
			</p>
		</div>

		<?php if ( $ha_ready ) : ?>
			<form method="post">
				<?php wp_nonce_field( 'honest-analytics-import' ); ?>

				<input type="hidden" name="honest_import_action" value="start" />
				<input type="hidden" name="range" value="<?php echo esc_attr( $ha_chosen['choice'] ); ?>" />
				<input type="hidden" name="from" value="<?php echo esc_attr( $ha_chosen['from'] ); ?>" />
				<input type="hidden" name="to" value="<?php echo esc_attr( $ha_chosen['to'] ); ?>" />
				<input type="hidden" name="overlap" value="<?php echo esc_attr( $ha_chosen['overlap'] ); ?>" />

				<div class="ha-wizard-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Start import', 'honest-analytics' ); ?></button>

					<a class="ha-wizard-cancel" href="<?php echo esc_url( $screen->homeUrl() ); ?>">
						<?php esc_html_e( 'Cancel', 'honest-analytics' ); ?>
					</a>
				</div>
			</form>
		<?php elseif ( ! $ha_detected->isAvailable() ) : ?>
			<div class="ha-info is-warning">
				<p>
					<?php
					echo esc_html(
						'' !== $ha_detected->detail
							? $ha_detected->detail
							: sprintf(
								/* translators: %s: the name of the analytics tool. */
								__( 'There is no %s data on this site to import.', 'honest-analytics' ),
								$importer->name()
							)
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="ha-info is-warning">
				<p><?php esc_html_e( 'Importing is not available on this installation, so this preview is for information only.', 'honest-analytics' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ( [] !== $ha_summary->mappings ) : ?>
	<details class="ha-card ha-more-detail">
		<summary class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'Exactly what becomes what', 'honest-analytics' ); ?></h2>
		</summary>

		<div class="ha-card-body">
			<?php
			\HonestAnalytics\Admin\Views\View::render(
				'admin/import/partials/mappings',
				[ 'mappings' => $ha_summary->mappings ]
			);
			?>
		</div>
	</details>
<?php endif; ?>
