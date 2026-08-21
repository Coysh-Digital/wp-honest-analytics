<?php
/**
 * The Content screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ha-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Content dimension', 'honest-analytics' ); ?>">
	<?php foreach ( $tabs as $ha_key => $ha_label ) : ?>
		<a
			class="ha-tab<?php echo $tab === $ha_key ? ' is-selected' : ''; ?>"
			role="tab"
			aria-selected="<?php echo $tab === $ha_key ? 'true' : 'false'; ?>"
			href="<?php echo esc_url( $params->url( [ 'tab' => $ha_key ] ) ); ?>"
		><?php echo esc_html( $ha_label ); ?></a>
	<?php endforeach; ?>
</div>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php echo esc_html( $heading ); ?></h2>
		<span class="ha-card-note"><?php echo esc_html( $params->range->label ); ?></span>
	</div>

	<div class="ha-card-body">
		<?php
		$ha_rows = [];

		foreach ( $rows as $ha_row ) {
			$ha_rows[] = [
				'label'   => $ha_row['label'],
				'views'   => Format::count( $ha_row['views'] ),
				'posts'   => Format::count( $ha_row['posts'] ),
				'perPost' => Format::count( $ha_row['perPost'] ),
				'time'    => $beacon && $ha_row['avgDwellMs'] > 0 ? Format::duration( $ha_row['avgDwellMs'] ) : '-',
				'_bar'    => $ha_row['views'],
			];
		}

		View::render(
			'admin/partials/ranked-table',
			[
				'rows'    => $ha_rows,
				'max'     => $maxViews,
				'wide'    => 'is-wider',
				'columns' => [
					[
						'key'   => 'label',
						'label' => $column,
					],
					[
						'key'     => 'views',
						'label'   => __( 'Views', 'honest-analytics' ),
						'numeric' => true,
					],
					[
						'key'   => 'posts',
						'label' => __( 'Posts', 'honest-analytics' ),
					],
					[
						'key'     => 'perPost',
						'label'   => __( 'Views each', 'honest-analytics' ),
						'numeric' => true,
					],
					[
						'key'   => 'time',
						'label' => __( 'Avg time', 'honest-analytics' ),
					],
				],
			]
		);
		?>
	</div>
</div>

<p class="ha-footnote is-tight">
	<?php esc_html_e( 'Views are joined to the content model as it stands now, so moving a post between post types moves its history with it.', 'honest-analytics' ); ?>
	<strong><?php esc_html_e( 'Views each', 'honest-analytics' ); ?></strong>
	<?php esc_html_e( 'is the column worth reading: a post type with 20 posts and 2,000 views is not clearly beating one with 2 posts and 800.', 'honest-analytics' ); ?>
</p>

<p class="ha-footnote is-tight">
	<?php esc_html_e( 'Pages that are not posts - archives, search results, plugin-provided endpoints - have views but no post to attribute them to, and appear only under Pages. Drafts, private posts, revisions and deleted posts are excluded.', 'honest-analytics' ); ?>
	<?php if ( 'author' === $tab ) : ?>
		<?php esc_html_e( 'Each post is credited to its listed author; a post with several contributors is credited once, to the author WordPress records.', 'honest-analytics' ); ?>
	<?php endif; ?>
</p>
