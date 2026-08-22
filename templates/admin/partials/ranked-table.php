<?php
/**
 * A ranked table with proportional background bars.
 *
 * The bar is a wash behind the label and the number is always spelled out
 * beside it, so nothing is communicated by length or colour alone.
 *
 * A column marked `'delta' => true` gets a small comparison badge beside its
 * value, read from `$row[$key . '_delta']` - a float, or absent/null when
 * there is nothing to compare this row against (no comparison active, or no
 * counterpart for this row in the comparison period). Absent means no badge,
 * never a badge reading zero: those are different facts.
 *
 * @package HonestAnalytics
 *
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array<string,mixed>> $columns
 * @var int                            $max
 * @var string                         $empty
 * @var string                         $wide
 */

declare(strict_types=1);

use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_rows    = $rows ?? [];
$ha_columns = $columns ?? [];
$ha_max     = max( 1, (int) ( $max ?? 1 ) );
$ha_wide    = $wide ?? '';
?>
<?php if ( [] === $ha_rows ) : ?>
	<p class="ha-muted"><?php echo esc_html( $empty ?? __( 'Nothing recorded for this period.', 'honest-analytics' ) ); ?></p>
<?php else : ?>
	<div class="ha-scroll">
		<table class="ha-table<?php echo '' !== $ha_wide ? ' ' . esc_attr( $ha_wide ) : ''; ?>">
			<thead>
				<tr>
					<?php foreach ( $ha_columns as $ha_column ) : ?>
						<th scope="col"<?php echo ! empty( $ha_column['numeric'] ) ? ' class="ha-num"' : ''; ?>>
							<?php echo esc_html( $ha_column['label'] ); ?>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>

			<tbody>
				<?php foreach ( $ha_rows as $ha_row ) : ?>
					<tr>
						<?php foreach ( $ha_columns as $ha_index => $ha_column ) : ?>
							<?php
							$ha_key   = $ha_column['key'];
							$ha_value = $ha_row[ $ha_key ] ?? '';
							$ha_first = 0 === $ha_index;
							$ha_width = $ha_first ? round( ( (float) ( $ha_row['_bar'] ?? 0 ) / $ha_max ) * 100, 1 ) : 0;
							?>
							<td class="<?php echo $ha_first ? 'ha-bar-cell' : ( ! empty( $ha_column['numeric'] ) ? 'ha-num' : 'ha-num-muted' ); ?>">
								<?php if ( $ha_first ) : ?>
									<span class="ha-bar-fill" style="width:<?php echo esc_attr( (string) $ha_width ); ?>%"></span>
									<span class="ha-bar-label<?php echo ! empty( $ha_column['mono'] ) ? ' ha-path-cell' : ''; ?>">
										<?php if ( ! empty( $ha_row['_url'] ) ) : ?>
											<a href="<?php echo esc_url( (string) $ha_row['_url'] ); ?>"><?php echo esc_html( (string) $ha_value ); ?></a>
										<?php else : ?>
											<?php echo esc_html( (string) $ha_value ); ?>
										<?php endif; ?>
									</span>
								<?php else : ?>
									<?php echo esc_html( (string) $ha_value ); ?>
									<?php
									$ha_delta = ! empty( $ha_column['delta'] ) && array_key_exists( $ha_key . '_delta', $ha_row )
										? $ha_row[ $ha_key . '_delta' ]
										: null;
									?>
									<?php if ( null !== $ha_delta ) : ?>
										<?php
										$ha_delta       = (float) $ha_delta;
										$ha_delta_good  = ! empty( $ha_column['deltaInverse'] ) ? $ha_delta < 0 : $ha_delta > 0;
										$ha_delta_class = abs( $ha_delta ) < 0.05 ? 'is-flat' : ( $ha_delta_good ? 'is-up' : 'is-down' );
										?>
										<span class="ha-delta ha-delta-inline <?php echo esc_attr( $ha_delta_class ); ?>"><?php echo esc_html( Format::delta( $ha_delta ) ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
