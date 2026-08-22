<?php
/**
 * The Pages screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php View::render( 'admin/partials/kpis', [ 'kpis' => $kpis ] ); ?>

<?php if ( null !== $compareChart ) : ?>
	<div class="ha-card">
		<div class="ha-card-head">
			<h2 class="ha-card-title"><?php esc_html_e( 'Pages compared', 'honest-analytics' ); ?></h2>

			<div class="ha-legend">
				<?php foreach ( $compareChart['datasets'] as $ha_dataset ) : ?>
					<span class="ha-legend-item">
						<span class="ha-swatch is-<?php echo esc_attr( $ha_dataset['token'] ); ?>"></span>
						<span class="ha-path-cell"><?php echo esc_html( $ha_dataset['label'] ); ?></span>
					</span>
				<?php endforeach; ?>

				<a href="<?php echo esc_url( $params->url() ); ?>"><?php esc_html_e( 'Clear comparison', 'honest-analytics' ); ?></a>
			</div>
		</div>

		<div class="ha-card-body">
			<?php
			View::render(
				'admin/partials/chart',
				[
					'id'      => 'ha-compare',
					'payload' => $compareChart,
					'label'   => __( 'Daily views for the selected pages.', 'honest-analytics' ),
					'caption' => __( 'Daily views by page', 'honest-analytics' ),
				]
			);
			?>
		</div>
	</div>
<?php endif; ?>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" data-ha-compare-form data-ha-compare-limit="<?php echo esc_attr( (string) ChartData::MAX_SERIES ); ?>">
	<input type="hidden" name="page" value="<?php echo esc_attr( $params->screen ); ?>" />
	<input type="hidden" name="range" value="<?php echo esc_attr( $params->range->param ); ?>" />

	<div class="ha-card">
		<div class="ha-card-head">
			<div class="ha-filters">
				<label>
					<?php esc_html_e( 'Path contains', 'honest-analytics' ); ?>
					<input type="search" name="q" value="<?php echo esc_attr( $params->include ); ?>" placeholder="/blog" />
				</label>

				<label>
					<?php esc_html_e( 'Path excludes', 'honest-analytics' ); ?>
					<input type="search" name="exclude" value="<?php echo esc_attr( $params->exclude ); ?>" placeholder="/wp-admin" />
				</label>

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'honest-analytics' ); ?></button>

				<?php if ( '' !== $params->include || '' !== $params->exclude ) : ?>
					<a class="button-link" href="
					<?php
					echo esc_url(
						$params->url(
							[
								'q'       => null,
								'exclude' => null,
							]
						)
					);
					?>
													"><?php esc_html_e( 'Clear', 'honest-analytics' ); ?></a>
				<?php endif; ?>
			</div>

			<span class="ha-card-note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of pages. */
						_n( '%s page', '%s pages', count( $rows ), 'honest-analytics' ),
						number_format_i18n( count( $rows ) )
					)
				);
				?>
			</span>
		</div>

		<div class="ha-card-body">
			<?php if ( [] === $rows ) : ?>
				<div class="ha-empty">
					<div class="ha-empty-title">
						<?php
						echo esc_html(
							( '' !== $params->include || '' !== $params->exclude )
								? __( 'No pages match that filter', 'honest-analytics' )
								: __( 'No pages recorded for this period', 'honest-analytics' )
						);
						?>
					</div>
				</div>
			<?php else : ?>
				<div class="ha-scroll">
					<table class="ha-table is-widest">
						<thead>
							<tr>
								<th scope="col" class="is-check">
									<span class="ha-visually-hidden"><?php esc_html_e( 'Select for comparison', 'honest-analytics' ); ?></span>
								</th>
								<th scope="col"><?php esc_html_e( 'Page', 'honest-analytics' ); ?></th>
								<th scope="col" class="ha-num"><?php esc_html_e( 'Views', 'honest-analytics' ); ?></th>
								<th scope="col" class="ha-num"><?php esc_html_e( 'Entrances', 'honest-analytics' ); ?></th>
								<th scope="col" class="ha-num"><?php esc_html_e( 'Exits', 'honest-analytics' ); ?></th>
								<th scope="col" class="ha-num"><?php esc_html_e( 'Bounce rate', 'honest-analytics' ); ?></th>
								<th scope="col" class="ha-num"><?php esc_html_e( 'Avg time', 'honest-analytics' ); ?></th>
							</tr>
						</thead>

						<tbody>
							<?php foreach ( $rows as $ha_row ) : ?>
								<?php
								$ha_isOther = DimensionType::OTHER_VALUE === $ha_row['path'];
								$ha_width   = $maxViews > 0 ? round( $ha_row['views'] / $maxViews * 100, 1 ) : 0;
								$ha_edit    = $editUrls[ $ha_row['postId'] ] ?? null;
								?>
								<tr>
									<td class="is-centre">
										<?php if ( ! $ha_isOther ) : ?>
											<input
												type="checkbox"
												name="compare[]"
												value="<?php echo esc_attr( $ha_row['path'] ); ?>"
												<?php checked( in_array( $ha_row['path'], $compare, true ) ); ?>
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: page path. */ __( 'Compare %s', 'honest-analytics' ), $ha_row['path'] ) ); ?>"
											/>
										<?php endif; ?>
									</td>

									<td class="ha-bar-cell">
										<span class="ha-bar-fill" style="width:<?php echo esc_attr( (string) $ha_width ); ?>%"></span>

										<span class="ha-bar-label ha-inline">
											<?php if ( $ha_isOther ) : ?>
												<span
													class="ha-muted"
													title="<?php echo esc_attr( sprintf( /* translators: %s: the daily cardinality cap. */ __( 'Not a real page. Paths beyond the daily cap of %s distinct pages are folded in here. No views are lost.', 'honest-analytics' ), number_format_i18n( $dimensionCap ) ) ); ?>"
												><?php esc_html_e( 'Other', 'honest-analytics' ); ?></span>
											<?php else : ?>
												<a class="ha-path-cell" href="<?php echo esc_url( $params->url( [ 'path' => $ha_row['path'] ] ) ); ?>"><?php echo esc_html( $ha_row['path'] ); ?></a>

												<?php if ( null !== $ha_edit ) : ?>
													<a class="ha-row-edit" href="<?php echo esc_url( $ha_edit ); ?>"><?php esc_html_e( 'Edit', 'honest-analytics' ); ?></a>
												<?php endif; ?>
											<?php endif; ?>
										</span>
									</td>

									<td class="ha-num">
										<?php echo esc_html( Format::count( $ha_row['views'] ) ); ?>
										<?php $ha_delta = $ha_row['views_delta'] ?? null; ?>
										<?php if ( null !== $ha_delta ) : ?>
											<?php
											$ha_delta       = (float) $ha_delta;
											$ha_delta_class = abs( $ha_delta ) < 0.05 ? 'is-flat' : ( $ha_delta > 0 ? 'is-up' : 'is-down' );
											?>
											<span class="ha-delta ha-delta-inline <?php echo esc_attr( $ha_delta_class ); ?>"><?php echo esc_html( Format::delta( $ha_delta ) ); ?></span>
										<?php endif; ?>
									</td>
									<td class="ha-num-muted"><?php echo esc_html( $ha_row['entrances'] > 0 ? Format::count( $ha_row['entrances'] ) : '-' ); ?></td>
									<td class="ha-num-muted"><?php echo esc_html( $ha_row['exits'] > 0 ? Format::count( $ha_row['exits'] ) : '-' ); ?></td>
									<td class="ha-num-muted"><?php echo esc_html( null !== $ha_row['bounceRate'] ? Format::percent( (float) $ha_row['bounceRate'] ) : '-' ); ?></td>
									<td class="ha-num-muted"><?php echo esc_html( $beacon && $ha_row['avgDwellMs'] > 0 ? Format::duration( $ha_row['avgDwellMs'] ) : '-' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="ha-compare-bar">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Compare selected', 'honest-analytics' ); ?></button>

					<span
						class="ha-muted"
						data-ha-compare-status
						<?php /* translators: 1: number selected, 2: maximum. */ ?>
						data-template="<?php echo esc_attr( __( '%1$d of %2$d pages selected.', 'honest-analytics' ) ); ?>"
					>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number selected, 2: maximum. */
								__( '%1$d of %2$d pages selected.', 'honest-analytics' ),
								count( $compare ),
								ChartData::MAX_SERIES
							)
						);
						?>
					</span>

					<span class="ha-muted"><?php esc_html_e( 'The comparison is written into the URL, so it can be bookmarked or sent to someone.', 'honest-analytics' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	</div>
</form>

<p class="ha-footnote">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: the daily cardinality cap. */
			__( '“Other” holds paths beyond the daily cardinality cap of %s. No views are lost, they are just not attributed to an individual path, which is why the row does not link anywhere.', 'honest-analytics' ),
			number_format_i18n( $dimensionCap )
		)
	);
	?>
	<?php esc_html_e( 'Bounce rate is derived from entrances and shows “-” where a page has none: a bounce is only counted against the page a session entered on.', 'honest-analytics' ); ?>
	<?php if ( ! $beacon ) : ?>
		<?php esc_html_e( 'Average time needs the beacon, so it is blank in server-only tracking mode.', 'honest-analytics' ); ?>
	<?php endif; ?>
</p>
