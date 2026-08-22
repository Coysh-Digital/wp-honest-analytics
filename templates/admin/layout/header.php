<?php
/**
 * Screen chrome: heading, export button and the date range.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\Screen $screen
 * @var \HonestAnalytics\Admin\RequestParams  $params
 */

declare(strict_types=1);

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Export\ExportHandler;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Stats\Granularity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_range         = $params->range;
$ha_export        = $screen->exportKind();
$ha_can_export    = null !== $ha_export && current_user_can( Capabilities::EXPORT );
$ha_granularities = Granularity::availableFor( $ha_range );
?>
<div class="wrap">
	<div class="ha-wrap<?php echo 900 === $screen->maxWidth() ? ' is-narrow' : ''; ?>">

		<div class="ha-header">
			<h1<?php echo '' !== $screen->headingClass() ? ' class="' . esc_attr( $screen->headingClass() ) . '"' : ''; ?>><?php echo esc_html( $screen->heading() ); ?></h1>

			<?php if ( $ha_can_export ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( ExportHandler::url( $ha_export, $ha_range ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'honest-analytics' ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( ExportHandler::url( $ha_export, $ha_range, 'json' ) ); ?>">
					<?php esc_html_e( 'JSON', 'honest-analytics' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php settings_errors( 'honest_analytics' ); ?>

		<?php if ( $screen->hasRange() ) : ?>
			<div class="ha-toolbar">
				<div class="ha-ranges" role="group" aria-label="<?php esc_attr_e( 'Date range', 'honest-analytics' ); ?>">
					<?php foreach ( DateRange::presets() as $ha_preset => $ha_label ) : ?>
						<a
							class="ha-range<?php echo $ha_range->preset === $ha_preset ? ' is-selected' : ''; ?>"
							href="<?php echo esc_url( $params->url( [ 'range' => $ha_preset ] ) ); ?>"
							<?php echo $ha_range->preset === $ha_preset ? 'aria-current="true"' : ''; ?>
						><?php echo esc_html( $ha_label ); ?></a>
					<?php endforeach; ?>

					<details class="ha-range-custom"<?php echo DateRange::PRESET_CUSTOM === $ha_range->preset ? ' open' : ''; ?>>
						<summary class="ha-range<?php echo DateRange::PRESET_CUSTOM === $ha_range->preset ? ' is-selected' : ''; ?>">
							<?php esc_html_e( 'Custom', 'honest-analytics' ); ?>
						</summary>

						<form class="ha-range-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<input type="hidden" name="page" value="<?php echo esc_attr( $params->screen ); ?>" />

							<?php foreach ( $params->carried() as $ha_key => $ha_value ) : ?>
								<?php if ( 'range' !== $ha_key ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $ha_key ); ?>" value="<?php echo esc_attr( $ha_value ); ?>" />
								<?php endif; ?>
							<?php endforeach; ?>

							<label>
								<?php esc_html_e( 'From', 'honest-analytics' ); ?>
								<input type="date" name="from" value="<?php echo esc_attr( $ha_range->from ); ?>" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required />
							</label>

							<label>
								<?php esc_html_e( 'To', 'honest-analytics' ); ?>
								<input type="date" name="to" value="<?php echo esc_attr( $ha_range->to ); ?>" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required />
							</label>

							<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'honest-analytics' ); ?></button>
						</form>
					</details>
				</div>

				<?php if ( count( $ha_granularities ) > 1 ) : ?>
					<div class="ha-toolbar-group" role="group" aria-label="<?php esc_attr_e( 'Chart grouping', 'honest-analytics' ); ?>">
						<?php foreach ( $ha_granularities as $ha_granularity ) : ?>
							<a
								class="ha-range<?php echo $params->granularity === $ha_granularity ? ' is-selected' : ''; ?>"
								href="<?php echo esc_url( $params->url( [ 'granularity' => $ha_granularity->value ] ) ); ?>"
								<?php echo $params->granularity === $ha_granularity ? 'aria-current="true"' : ''; ?>
							><?php echo esc_html( $ha_granularity->label() ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="ha-toolbar-group" role="group" aria-label="<?php esc_attr_e( 'Compare', 'honest-analytics' ); ?>">
					<?php
					foreach (
						[
							''         => __( 'No comparison', 'honest-analytics' ),
							'previous' => __( 'Previous period', 'honest-analytics' ),
							'year'     => __( 'Same period last year', 'honest-analytics' ),
						] as $ha_compare_period => $ha_compare_label
					) :
						?>
						<a
							class="ha-range<?php echo $params->comparePeriod === $ha_compare_period ? ' is-selected' : ''; ?>"
							href="<?php echo esc_url( $params->url( [ 'compare_period' => '' === $ha_compare_period ? 'none' : $ha_compare_period ] ) ); ?>"
							<?php echo $params->comparePeriod === $ha_compare_period ? 'aria-current="true"' : ''; ?>
						><?php echo esc_html( $ha_compare_label ); ?></a>
					<?php endforeach; ?>
				</div>

				<span class="ha-range-note">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: start date, 2: end date. */
							__( '%1$s - %2$s', 'honest-analytics' ),
							wp_date( 'j M Y', (int) strtotime( $ha_range->from ) ),
							wp_date( 'j M Y', (int) strtotime( $ha_range->to ) )
						)
					);
					?>
				</span>
			</div>
		<?php endif; ?>
