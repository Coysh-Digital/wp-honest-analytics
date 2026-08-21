<?php
/**
 * The Pages screen, and the single-page view.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Posts\PostLinks;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every page, and one page.
 *
 * The detail view lives on the same slug rather than on a hidden submenu, so
 * the Pages item stays highlighted while somebody is looking at a page.
 */
final class PagesScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-pages';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Pages', 'honest-analytics' );
	}

	/**
	 * What this screen exports.
	 */
	public function exportKind(): string {
		return 'pages';
	}

	/**
	 * The path, when one is being looked at.
	 */
	public function heading(): string {
		$path = $this->params()->path;

		return '' !== $path ? $path : $this->title();
	}

	/**
	 * A path is rendered as a path.
	 */
	public function headingClass(): string {
		return '' !== $this->params()->path ? 'ha-path' : '';
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( '' !== $this->params()->path ) {
			$this->renderDetail();

			return;
		}

		$plugin = Plugin::instance();
		$stats  = $plugin->stats();
		$params = $this->params();
		$siteId = $this->siteId();

		$rows = $stats->topPages(
			$siteId,
			$params->range,
			200,
			'' !== $params->include ? $params->include : null,
			'' !== $params->exclude ? $params->exclude : null
		);

		$compare      = $params->validatedComparePaths( $rows );
		$compareChart = [] !== $compare ? $this->compareChart( $siteId, $compare ) : null;

		View::render(
			'admin/pages',
			[
				'params'       => $params,
				'rows'         => $rows,
				'compare'      => $compare,
				'compareChart' => $compareChart,
				'editUrls'     => PostLinks::editUrls( array_column( $rows, 'postId' ) ),
				'dimensionCap' => $plugin->settings()->dimensionCap,
				'maxViews'     => $rows ? max( array_column( $rows, 'views' ) ) : 0,
				'beacon'       => $plugin->settings()->usesBeacon(),
			]
		);
	}

	/**
	 * Render one page's detail.
	 */
	private function renderDetail(): void {
		$plugin = Plugin::instance();
		$stats  = $plugin->stats();
		$params = $this->params();
		$siteId = $this->siteId();
		$path   = $params->path;

		// Looked up, never created: an unvisited path is a 404 rather than a
		// page of zeroes and a new dimension row minted by somebody guessing.
		$pathDimId = $plugin->dimensions()->getId( DimensionType::Path, $path );

		if ( null === $pathDimId ) {
			wp_die(
				esc_html__( 'No analytics have been recorded for that page.', 'honest-analytics' ),
				esc_html__( 'Not found', 'honest-analytics' ),
				[ 'response' => 404 ]
			);
		}

		$range    = $params->range;
		$totals   = $stats->pageTotals( $siteId, $range, $pathDimId );
		$previous = $stats->pageTotals( $siteId, $range->previous(), $pathDimId );
		$isPro    = Edition::isPro();

		View::render(
			'admin/page',
			[
				'params'       => $params,
				'path'         => $path,
				'totals'       => $totals,
				'previous'     => $previous,
				'accuracy'     => $stats->uniquesAccuracy(),
				'trendChart'   => ChartData::trend( $stats->trend( $siteId, $range, $pathDimId ) ),
				'sources'      => $stats->pageSources( $siteId, $range, $pathDimId ),
				'sourcesSince' => $stats->pageSourcesSince( $siteId ),
				'editUrl'      => $totals['postId'] > 0 ? PostLinks::editUrl( (int) $totals['postId'] ) : null,
				'isPro'        => $isPro,
				'beacon'       => $plugin->settings()->usesBeacon(),
				'scroll'       => $isPro ? ( $plugin->proStats()->scrollDepth( $siteId, $range, 1, $pathDimId )[0] ?? null ) : null,
				'events'       => $isPro ? $plugin->proStats()->events( $siteId, $range, 20, $pathDimId ) : [],
				'outbound'     => $isPro ? $plugin->proStats()->outbound( $siteId, $range, 20, $pathDimId ) : [],
			]
		);
	}

	/**
	 * Build the comparison chart.
	 *
	 * Series are ordered by the checkbox order rather than by size, so the
	 * legend matches the list somebody just ticked.
	 *
	 * @param int      $siteId Site ID.
	 * @param string[] $paths  Validated paths.
	 *
	 * @return array<string,mixed>
	 */
	private function compareChart( int $siteId, array $paths ): array {
		$params = $this->params();
		$stats  = Plugin::instance()->stats();
		$dates  = $params->range->dates();

		$series = ChartData::pivot(
			$stats->pathsTrend( $siteId, $params->range, $paths ),
			$dates,
			'path',
			'views'
		);

		$ordered = [];

		foreach ( $paths as $path ) {
			$ordered[ $path ] = $series[ $path ] ?? array_fill( 0, count( $dates ), 0 );
		}

		$labels  = ChartData::axisLabels( $dates, false );
		$payload = ChartData::line( $labels['axis'], ChartData::datasets( $ordered ) );

		$payload['full'] = $labels['full'];

		return $payload;
	}
}
