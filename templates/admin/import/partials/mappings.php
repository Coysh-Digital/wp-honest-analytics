<?php
/**
 * The metric-by-metric mapping, with its honesty on show.
 *
 * The right-hand column is the point of this table. "Approximate" is not a
 * hedge - it is the difference between two systems that use the same word for
 * two different things, and saying so here is cheaper than explaining it to
 * somebody after their visitor figures moved.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Import\MetricMapping[] $mappings
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ha-scroll">
	<table class="ha-table is-wide">
		<caption class="ha-visually-hidden"><?php esc_html_e( 'How each metric maps into this plugin', 'honest-analytics' ); ?></caption>

		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'From', 'honest-analytics' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Becomes', 'honest-analytics' ); ?></th>
				<th scope="col"><?php esc_html_e( 'How close', 'honest-analytics' ); ?></th>
			</tr>
		</thead>

		<tbody>
			<?php foreach ( $mappings as $ha_mapping ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $ha_mapping->sourceMetric ); ?></th>
					<td><?php echo esc_html( $ha_mapping->metric ); ?></td>
					<td>
						<span class="ha-badge<?php echo $ha_mapping->isExact() ? '' : ' is-approximate'; ?>">
							<?php
							echo esc_html(
								$ha_mapping->isExact()
									? __( 'The same thing', 'honest-analytics' )
									: __( 'Close, not identical', 'honest-analytics' )
							);
							?>
						</span>

						<?php if ( '' !== $ha_mapping->note ) : ?>
							<p class="ha-muted ha-fine ha-tight"><?php echo esc_html( $ha_mapping->note ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php
$ha_approximate = false;

foreach ( $mappings as $ha_mapping ) {
	if ( ! $ha_mapping->isExact() ) {
		$ha_approximate = true;

		break;
	}
}
?>

<?php if ( $ha_approximate ) : ?>
	<p class="ha-footnote">
		<?php esc_html_e( 'Anything marked “close, not identical” measures roughly the same thing by a different method. It is useful for trends and comparisons, and it is not a figure to reconcile to the last unit against what this plugin measures itself.', 'honest-analytics' ); ?>
	</p>
<?php endif; ?>
