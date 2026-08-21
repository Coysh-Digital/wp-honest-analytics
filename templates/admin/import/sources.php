<?php
/**
 * The landing step: what we found on this site.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen    $screen
 * @var \HonestAnalytics\Import\ImporterInterface[]    $importers
 * @var bool                                           $available
 */

declare(strict_types=1);

use HonestAnalytics\Import\DetectionResult;
use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One source's card. Detected sources get a button; the rest get a sentence.
 *
 * @param \HonestAnalytics\Admin\Screens\ImportScreen $screen   The screen.
 * @param \HonestAnalytics\Import\ImporterInterface   $importer The source.
 * @param bool                                        $enabled  Whether importing works at all.
 */
$ha_card = static function ( $screen, $importer, bool $enabled ): void {
	$detected = $importer->detect();
	$last     = $screen->lastJobFor( $importer->id() );
	$done     = null !== $last && $last->isComplete();
	$running  = null !== $last && $last->isActive();
	?>
	<div class="ha-source">
		<div class="ha-source-main">
			<h3 class="ha-source-name"><?php echo esc_html( $importer->name() ); ?></h3>

			<?php if ( $done ) : ?>
				<p class="ha-source-state is-done">
					<span aria-hidden="true">&#10003;</span>
					<?php esc_html_e( 'Imported', 'honest-analytics' ); ?>
				</p>

				<p class="ha-muted ha-tight">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start date, 2: end date. */
							__( '%1$s to %2$s', 'honest-analytics' ),
							wp_date( 'j M Y', (int) strtotime( $last->configuration->dateFrom ) ),
							wp_date( 'j M Y', (int) strtotime( $last->configuration->dateTo ) )
						)
					);
					?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: a number of records, already formatted. */
							__( ' · %s records imported', 'honest-analytics' ),
							Format::count( $last->recordsImported )
						)
					);
					?>
				</p>
			<?php elseif ( $running ) : ?>
				<p class="ha-source-state is-running"><?php esc_html_e( 'Import in progress', 'honest-analytics' ); ?></p>
				<p class="ha-muted ha-tight"><?php esc_html_e( 'Running in the background.', 'honest-analytics' ); ?></p>
			<?php elseif ( $detected->isAvailable() ) : ?>
				<p class="ha-source-state is-found">
					<span aria-hidden="true">&#10003;</span>
					<?php esc_html_e( 'Found on this website', 'honest-analytics' ); ?>
				</p>

				<p class="ha-muted ha-tight">
					<?php
					if ( null !== $detected->dateFrom && null !== $detected->dateTo ) {
						echo esc_html(
							sprintf(
								/* translators: 1: start date, 2: end date. */
								__( 'History from %1$s to %2$s is ready to import.', 'honest-analytics' ),
								wp_date( 'j M Y', (int) strtotime( $detected->dateFrom ) ),
								wp_date( 'j M Y', (int) strtotime( $detected->dateTo ) )
							)
						);
					} else {
						esc_html_e( 'Historical data is ready to import.', 'honest-analytics' );
					}
					?>
				</p>
			<?php elseif ( DetectionResult::EMPTY_SOURCE === $detected->state ) : ?>
				<p class="ha-source-state"><?php esc_html_e( 'Found, but empty', 'honest-analytics' ); ?></p>
				<p class="ha-muted ha-tight"><?php echo esc_html( '' !== $detected->detail ? $detected->detail : __( 'There is no history here to bring across.', 'honest-analytics' ) ); ?></p>
			<?php elseif ( DetectionResult::UNSUPPORTED === $detected->state ) : ?>
				<p class="ha-source-state"><?php esc_html_e( 'Found, but not readable', 'honest-analytics' ); ?></p>
				<p class="ha-muted ha-tight"><?php echo esc_html( '' !== $detected->detail ? $detected->detail : __( 'This version keeps its data in a shape this importer does not recognise.', 'honest-analytics' ) ); ?></p>
			<?php elseif ( 'ga4' === $importer->id() ) : ?>
				<p class="ha-source-state"><?php esc_html_e( 'Not connected yet', 'honest-analytics' ); ?></p>
				<p class="ha-muted ha-tight">
					<?php echo esc_html( '' !== $detected->detail ? $detected->detail : __( 'Connect your Google account to bring historical trends across from GA4.', 'honest-analytics' ) ); ?>
				</p>
			<?php else : ?>
				<p class="ha-source-state"><?php esc_html_e( 'Not detected on this website', 'honest-analytics' ); ?></p>
				<?php if ( '' !== $detected->detail ) : ?>
					<p class="ha-muted ha-tight"><?php echo esc_html( $detected->detail ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="ha-source-action">
			<?php if ( $running && null !== $last ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $screen->jobUrl( 'progress', $last->id ) ); ?>">
					<?php esc_html_e( 'View progress', 'honest-analytics' ); ?>
				</a>
			<?php elseif ( $done && null !== $last ) : ?>
				<a class="button" href="<?php echo esc_url( $screen->jobUrl( 'details', $last->id ) ); ?>">
					<?php esc_html_e( 'View details', 'honest-analytics' ); ?>
				</a>

				<?php if ( $enabled && $detected->isAvailable() ) : ?>
					<a class="ha-source-again" href="<?php echo esc_url( $screen->stepUrl( 'ga4' === $importer->id() ? 'connect' : 'notes', $importer->id() ) ); ?>">
						<?php esc_html_e( 'Import again', 'honest-analytics' ); ?>
					</a>
				<?php endif; ?>
			<?php elseif ( $detected->isAvailable() && $enabled ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $screen->stepUrl( 'ga4' === $importer->id() ? 'connect' : 'notes', $importer->id() ) ); ?>">
					<?php echo esc_html( 'ga4' === $importer->id() ? __( 'Connect Google Analytics', 'honest-analytics' ) : __( 'Import', 'honest-analytics' ) ); ?>
				</a>
			<?php elseif ( 'ga4' === $importer->id() && $enabled ) : ?>
				<a class="button" href="<?php echo esc_url( $screen->stepUrl( 'connect', $importer->id() ) ); ?>">
					<?php esc_html_e( 'Connect Google Analytics', 'honest-analytics' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php
};

$ha_found  = [];
$ha_others = [];

foreach ( $importers as $ha_importer ) {
	$ha_last = $screen->lastJobFor( $ha_importer->id() );

	// Anything on this site, anything already imported, and Google Analytics -
	// which lives in somebody's Google account rather than on the server, so
	// there is nothing here to detect and connecting is the only way to find
	// out. Hiding it behind a fold would hide the one source most people have.
	$ha_primary = $ha_importer->detect()->isPresent()
		|| 'ga4' === $ha_importer->id()
		|| ( null !== $ha_last && ImportJob::STATUS_CANCELLED !== $ha_last->status );

	if ( $ha_primary ) {
		$ha_found[] = $ha_importer;

		continue;
	}

	$ha_others[] = $ha_importer;
}

// With nothing found, a fold hiding everything is worse than no fold: the
// screen would be an empty card above a closed drawer.
if ( [] === $ha_found ) {
	$ha_found  = $ha_others;
	$ha_others = [];
}

$ha_anyDetected = false;

foreach ( $importers as $ha_importer ) {
	if ( $ha_importer->detect()->isAvailable() ) {
		$ha_anyDetected = true;

		break;
	}
}
?>
<div class="ha-card">
	<div class="ha-card-body">
		<p class="ha-lede ha-measure">
			<?php esc_html_e( 'Already using another analytics tool? Bring your history with you, so your charts don’t have to start from zero.', 'honest-analytics' ); ?>
		</p>

		<?php if ( [] === $importers ) : ?>
			<p class="ha-muted">
				<?php esc_html_e( 'No importers are available on this installation.', 'honest-analytics' ); ?>
			</p>
		<?php else : ?>
			<?php if ( ! $available ) : ?>
				<div class="ha-info is-warning">
					<p><?php esc_html_e( 'Importing is not available on this installation, so the sources below are shown for information only.', 'honest-analytics' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $ha_anyDetected ) : ?>
				<p class="ha-muted ha-tight-below">
					<?php esc_html_e( 'We didn’t find another analytics tool installed here. If you have history somewhere else, you can still bring it across - Google Analytics is connected rather than detected, and a plugin you have already removed may have left its data behind for us to read.', 'honest-analytics' ); ?>
				</p>
			<?php endif; ?>

			<div class="ha-sources">
				<?php foreach ( $ha_found as $ha_importer ) : ?>
					<?php $ha_card( $screen, $ha_importer, $available ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ( [] !== $ha_others ) : ?>
	<details class="ha-card ha-more-sources">
		<summary class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'Other import options', 'honest-analytics' ); ?></h2>
		</summary>

		<div class="ha-card-body">
			<div class="ha-sources">
				<?php foreach ( $ha_others as $ha_importer ) : ?>
					<?php $ha_card( $screen, $ha_importer, $available ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</details>
<?php endif; ?>

<p class="ha-footnote">
	<?php esc_html_e( 'Importing only ever reads from your other analytics tool. Nothing there is changed, moved or removed, and you can keep using it alongside this plugin for as long as you like.', 'honest-analytics' ); ?>
</p>
