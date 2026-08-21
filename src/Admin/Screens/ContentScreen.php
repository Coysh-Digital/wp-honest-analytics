<?php
/**
 * The Content screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Views joined to the content model.
 */
final class ContentScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-content';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Content', 'honest-analytics' );
	}

	/**
	 * What this screen exports.
	 */
	public function exportKind(): string {
		return 'content';
	}

	/**
	 * No charts here: three tables answer it better.
	 */
	public function usesCharts(): bool {
		return false;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$content = Plugin::instance()->contentStats();
		$params  = $this->params();
		$siteId  = $this->siteId();
		$range   = $params->range;

		$tabs = [
			'post_type' => __( 'Post types', 'honest-analytics' ),
			'taxonomy'  => __( 'Taxonomies', 'honest-analytics' ),
			'author'    => __( 'Authors', 'honest-analytics' ),
		];

		$tab = isset( $tabs[ $params->tab ] ) ? $params->tab : 'post_type';

		$rows = match ( $tab ) {
			'taxonomy' => $content->byTaxonomy( $siteId, $range ),
			'author'   => $content->byAuthor( $siteId, $range ),
			default    => $content->byPostType( $siteId, $range ),
		};

		View::render(
			'admin/content',
			[
				'params'   => $params,
				'tabs'     => $tabs,
				'tab'      => $tab,
				'rows'     => $rows,
				'maxViews' => $rows ? max( array_column( $rows, 'views' ) ) : 0,
				'heading'  => match ( $tab ) {
					'taxonomy' => __( 'Views by term', 'honest-analytics' ),
					'author'   => __( 'Views by author', 'honest-analytics' ),
					default    => __( 'Views by post type', 'honest-analytics' ),
				},
				'column'   => match ( $tab ) {
					'taxonomy' => __( 'Term', 'honest-analytics' ),
					'author'   => __( 'Author', 'honest-analytics' ),
					default    => __( 'Post type', 'honest-analytics' ),
				},
				'beacon'   => Plugin::instance()->settings()->usesBeacon(),
			]
		);
	}
}
