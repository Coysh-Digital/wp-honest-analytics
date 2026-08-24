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
use HonestAnalytics\Stats\Comparison;
use HonestAnalytics\Stats\Granularity;
use HonestAnalytics\Support\Format;

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

		// Site-wide totals, deliberately - not a sum of the table below, which
		// may be filtered or capped. A card that mirrored the filtered rows
		// would disagree with Dashboard's numbers the moment a filter is
		// active; mirroring the site total instead keeps every screen honest
		// about the same period.
		$comparison   = $params->comparisonRange();
		$totals       = $stats->totals( $siteId, $params->range );
		$totalsBefore = null !== $comparison ? $stats->totals( $siteId, $comparison ) : null;

		if ( null !== $comparison ) {
			$previousRows = $stats->topPages(
				$siteId,
				$comparison,
				200,
				'' !== $params->include ? $params->include : null,
				'' !== $params->exclude ? $params->exclude : null
			);

			// Keyed by pathDimId, not by rank: the top 200 pages this period
			// are not guaranteed to be the top 200 - or even present at all -
			// in the comparison period.
			$rows = Comparison::rows( $rows, $previousRows, 'pathDimId', [ 'views' ] );
		}

		View::render(
			'admin/pages',
			[
				'params'       => $params,
				'rows'         => $rows,
				'compare'      => $compare,
				'compareChart' => $compareChart,
				'kpis'         => DashboardScreen::kpis( $totals, $totalsBefore, $plugin->settings(), DashboardScreen::compareNote( $params->comparePeriod ) ),
				'editUrls'     => PostLinks::editUrls( array_column( $rows, 'postId' ) ),
				'dimensionCap' => $plugin->settings()->dimensionCap,
				'maxViews'     => Format::largest( $rows, 'views' ),
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

		$range      = $params->range;
		$comparison = $params->comparisonRange();
		$totals     = $stats->pageTotals( $siteId, $range, $pathDimId );
		$previous   = null !== $comparison ? $stats->pageTotals( $siteId, $comparison, $pathDimId ) : null;
		$isPro      = Edition::isPro();

		$trend           = $stats->trend( $siteId, $range, $pathDimId, $params->granularity );
		$comparisonTrend = null !== $comparison ? $stats->trend( $siteId, $comparison, $pathDimId, $params->granularity ) : null;

		View::render(
			'admin/page',
			[
				'params'       => $params,
				'path'         => $path,
				'totals'       => $totals,
				'previous'     => $previous,
				'compareNote'  => null !== $comparison ? DashboardScreen::compareNote( $params->comparePeriod ) : '',
				'accuracy'     => $stats->uniquesAccuracy(),
				'trendChart'   => ChartData::trend( $trend, $comparisonTrend, DashboardScreen::seriesLabel( $params->comparePeriod ) ),
				'sources'      => $stats->pageSources( $siteId, $range, $pathDimId ),
				'sourcesSince' => $stats->pageSourcesSince( $siteId ),
				'editUrl'      => $totals['postId'] > 0 ? PostLinks::editUrl( (int) $totals['postId'] ) : null,
				'isPro'        => $isPro,
				'beacon'       => $plugin->settings()->usesBeacon(),
				'scroll'       => $isPro ? ( $plugin->proStats()->scrollDepth( $siteId, $range, 1, $pathDimId )[0] ?? null ) : null,
				'events'       => $isPro ? $plugin->proStats()->events( $siteId, $range, 20, $pathDimId ) : [],
				'outbound'     => $isPro ? $plugin->proStats()->outbound( $siteId, $range, 20, $pathDimId ) : [],
				'queries'      => $isPro ? $plugin->proStats()->searchConsoleQueries( $siteId, $range, 20, $pathDimId ) : [],
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
		$params      = $this->params();
		$stats       = Plugin::instance()->stats();
		$granularity = $params->granularity;
		$buckets     = $granularity->bucketsFor( $params->range );

		$rows = $stats->pathsTrend( $siteId, $params->range, $paths );

		// Bucketing happens here rather than in a new query: the rows are
		// already one per day, and every grain coarser than a day is a
		// re-bucketing of rows that already exist.
		foreach ( $rows as &$row ) {
			$row['date'] = $granularity->bucketKeyFor( (string) $row['date'] );
		}
		unset( $row );

		$series = ChartData::pivot( $rows, $buckets, 'path', 'views' );

		$ordered = [];

		foreach ( $paths as $path ) {
			$ordered[ $path ] = $series[ $path ] ?? array_fill( 0, count( $buckets ), 0 );
		}

		$labels  = ChartData::axisLabels( $buckets, false, $granularity );
		$payload = ChartData::line( $labels['axis'], ChartData::datasets( $ordered ) );

		$payload['full'] = $labels['full'];

		return $payload;
	}
}
