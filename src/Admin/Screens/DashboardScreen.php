<?php
/**
 * The Dashboard screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Charts\Heatmap;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Plugin;
use HonestAnalytics\Schema\Schema;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Stats\Comparison;
use HonestAnalytics\Support\Format;
use HonestAnalytics\Write\SpoolStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything at a glance.
 */
final class DashboardScreen extends Screen {

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Analytics', 'honest-analytics' );
	}

	/**
	 * The menu label.
	 */
	public function menuLabel(): string {
		return __( 'Dashboard', 'honest-analytics' );
	}

	/**
	 * What this screen exports.
	 */
	public function exportKind(): string {
		return 'trend';
	}

	/**
	 * Where imported history gives way to what this plugin measured itself.
	 *
	 * Shown under the traffic chart when the two meet inside the period on
	 * screen, because a step in a line at the switchover date is the single
	 * most alarming thing a migration can do to somebody who was not told to
	 * expect it. One quiet sentence is enough; a chart covered in annotations
	 * is not.
	 *
	 * Deliberately rendered beside the chart rather than drawn on the canvas.
	 * The figures behind every chart are also a table, and an explanation that
	 * only exists as a line on a canvas is one a screen reader never hears and
	 * one that disappears the moment the chart fails to draw.
	 *
	 * @param int    $siteId Site.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 *
	 * @return array{date:string,sources:string[]}|null
	 */
	private function importBoundary( int $siteId, string $from, string $to ): ?array {
		global $wpdb;

		if ( ! class_exists( Tables::class ) || ! Schema::tableExists( Tables::IMPORT_COVERAGE ) ) {
			return null;
		}

		$table = Tables::name( Tables::IMPORT_COVERAGE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Import tables have no core API and are deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, MAX(date) AS lastDate FROM `$table`
				WHERE siteId = %d AND date BETWEEN %s AND %s
				GROUP BY source",
				$siteId,
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( [] === (array) $rows ) {
			return null;
		}

		$date    = '';
		$sources = [];

		foreach ( (array) $rows as $row ) {
			$sources[] = ImportSource::label( (string) $row['source'] );
			$date      = max( $date, (string) $row['lastDate'] );
		}

		// The boundary is only interesting where there is something on the
		// other side of it. Imported history that runs to the end of the period
		// has no "after" to compare against yet.
		if ( '' === $date || $date >= $to ) {
			return null;
		}

		return [
			'date'    => $date,
			'sources' => $sources,
		];
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$plugin     = Plugin::instance();
		$stats      = $plugin->stats();
		$params     = $this->params();
		$siteId     = $this->siteId();
		$range      = $params->range;
		$comparison = $params->comparisonRange();
		$isPro      = Edition::isPro();

		$totals       = $stats->totals( $siteId, $range );
		$totalsBefore = null !== $comparison ? $stats->totals( $siteId, $comparison ) : null;

		$trend           = $stats->trend( $siteId, $range, null, $params->granularity );
		$comparisonTrend = null !== $comparison ? $stats->trend( $siteId, $comparison, null, $params->granularity ) : null;

		$data = [
			'params'      => $params,
			'range'       => $range,
			'isPro'       => $isPro,
			'accuracy'    => $stats->uniquesAccuracy(),
			'realtime'    => $plugin->realtime()->snapshot( $siteId, null, 0 ),
			'kpis'        => self::kpis( $totals, $totalsBefore, $plugin->settings(), self::compareNote( $params->comparePeriod ) ),
			'trendChart'  => ChartData::trend( $trend, $comparisonTrend, self::seriesLabel( $params->comparePeriod ) ),
			'heatmap'     => Heatmap::grid( $stats->hourOfWeek( $siteId, $range ) ),
			'heatmapFrom' => $stats->hourlyWindowFrom(),
			'hourlyDays'  => $plugin->settings()->hourlyWindowDays,
			'topPages'    => $stats->topPages( $siteId, $range, 6 ),
			'channels'    => $stats->channels( $siteId, $range ),
			'devices'     => $stats->devices( $siteId, $range, 'deviceType' ),
			'postTypes'   => $plugin->contentStats()->byPostType( $siteId, $range, 5 ),
			// Lite never queries these. The cards still render, with a plain
			// sentence about what they would tell you - a gap where a card
			// should be reads as a bug, not as an edition boundary.
			'crawlers'    => $isPro ? $stats->crawlers( $siteId, $range, 4 ) : [],
			'crawlerHits' => $isPro ? $stats->crawlerRequests( $siteId, $range ) : 0,
			'goals'       => $isPro ? array_slice( $plugin->conversionStats()->goals( $siteId, $range ), 0, 4 ) : [],
			'campaigns'   => $isPro ? $plugin->proStats()->campaigns( $siteId, $range, 4 ) : [],
			'countries'   => $isPro ? $plugin->proStats()->countries( $siteId, $range, 5 ) : [],
			'emptyHint'   => 0 === $totals['views'] ? $this->emptyHint( $plugin->settings() ) : null,
			'boundary'    => $this->importBoundary( $siteId, $range->from, $range->to ),
		];

		View::render( 'admin/dashboard', $data );
	}

	/**
	 * The seven headline figures, with their comparisons.
	 *
	 * `$previous` is null exactly when no comparison is active - not merely
	 * when the baseline was zero, which {@see Comparison::delta()} already
	 * handles on its own. Every delta badge is absent in that case, not just
	 * the ones whose note names the comparison: a card that kept showing a
	 * change against a period the toggle says is off would be showing the
	 * one thing "No comparison" was chosen to turn off.
	 *
	 * @param array<string,int|float>      $totals      This period.
	 * @param array<string,int|float>|null $previous    The period being compared against, or null for no comparison.
	 * @param Settings                     $settings    Settings.
	 * @param string                       $compareNote What to call the comparison period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function kpis( array $totals, ?array $previous, Settings $settings, string $compareNote ): array {
		$beacon = $settings->usesBeacon();
		$delta  = static fn ( int|float $current, string $key ): ?float =>
			null !== $previous ? Comparison::delta( $current, $previous[ $key ] ) : null;
		$note   = null !== $previous ? $compareNote : '';

		return [
			[
				'label' => __( 'Pageviews', 'honest-analytics' ),
				'value' => Format::count( $totals['views'] ),
				'delta' => $delta( $totals['views'], 'views' ),
				'note'  => $note,
			],
			[
				'label' => __( 'Unique visitors', 'honest-analytics' ),
				'value' => Format::count( $totals['uniques'] ),
				'delta' => $delta( $totals['uniques'], 'uniques' ),
				'note'  => sprintf(
					/* translators: %s: accuracy, e.g. "±1.6%". */
					__( 'daily uniques, %s', 'honest-analytics' ),
					Plugin::instance()->stats()->uniquesAccuracy()
				),
			],
			[
				'label' => __( 'Sessions', 'honest-analytics' ),
				'value' => Format::count( $totals['sessions'] ),
				'delta' => $delta( $totals['sessions'], 'sessions' ),
				'note'  => $note,
			],
			[
				'label'   => __( 'Bounce rate', 'honest-analytics' ),
				'value'   => Format::percent( (float) $totals['bounceRate'] ),
				'delta'   => $delta( $totals['bounceRate'], 'bounceRate' ),
				'inverse' => true,
				'note'    => __( 'lower is better', 'honest-analytics' ),
			],
			[
				'label' => __( 'Pages / session', 'honest-analytics' ),
				'value' => number_format_i18n( (float) $totals['avgViewsPerSession'], 2 ),
				'delta' => $delta( $totals['avgViewsPerSession'], 'avgViewsPerSession' ),
				'note'  => $note,
			],
			[
				'label' => __( 'Avg session', 'honest-analytics' ),
				'value' => Format::duration( (int) $totals['avgDurationMs'] ),
				'delta' => $delta( $totals['avgDurationMs'], 'avgDurationMs' ),
				'note'  => __( 'closed sessions only', 'honest-analytics' ),
			],
			[
				'label' => __( 'Avg time on page', 'honest-analytics' ),
				'value' => $beacon ? Format::duration( (int) $totals['avgDwellMs'] ) : '-',
				'delta' => $beacon ? $delta( $totals['avgDwellMs'], 'avgDwellMs' ) : null,
				'note'  => $beacon
					? __( 'measured by the tracker', 'honest-analytics' )
					: __( 'needs hybrid or client mode', 'honest-analytics' ),
			],
		];
	}

	/**
	 * Why there is nothing to show.
	 *
	 * Costs one file size rather than a query, and answers the only question a
	 * reader has when a dashboard is empty.
	 *
	 * @param Settings $settings Settings.
	 *
	 * @return array{title:string,body:string}
	 */
	private function emptyHint( Settings $settings ): array {
		$status = new SpoolStatus( $settings );

		if ( $status->hasBacklog() ) {
			return [
				'title' => __( 'Pageviews are waiting to be counted', 'honest-analytics' ),
				'body'  => __( 'Traffic is being recorded, but the drain has not folded it into the reports yet. It runs every five minutes, and ordinary traffic drives it when cron is unavailable. If this persists, press “Drain now” in Settings.', 'honest-analytics' ),
			];
		}

		return [
			'title' => __( 'Nothing recorded for this period', 'honest-analytics' ),
			'body'  => __( 'Once somebody visits the site, their pageview is counted within a few minutes. Signed-in editors are left out by default, so opening the site in another tab while logged in will not register.', 'honest-analytics' ),
		];
	}

	/**
	 * The percentage change between two figures.
	 *
	 * @param int|float $current  This period.
	 * @param int|float $previous The period before.
	 *
	 * @deprecated Call {@see Comparison::delta()} directly.
	 */
	public static function delta( int|float $current, int|float $previous ): ?float {
		return Comparison::delta( $current, $previous );
	}

	/**
	 * What to call the period a KPI card is compared against.
	 *
	 * @param string $comparePeriod {@see \HonestAnalytics\Admin\RequestParams::$comparePeriod}.
	 */
	public static function compareNote( string $comparePeriod ): string {
		return 'year' === $comparePeriod
			? __( 'vs same period last year', 'honest-analytics' )
			: __( 'vs previous period', 'honest-analytics' );
	}

	/**
	 * The comparison line's own legend and tooltip label.
	 *
	 * Naming just the period - "Same period last year" - reads as a fourth
	 * metric sitting alongside Pageviews and Unique visitors rather than as
	 * Pageviews measured at a different time, which is what it actually is.
	 * Naming the metric too removes the ambiguity.
	 *
	 * @param string $comparePeriod {@see \HonestAnalytics\Admin\RequestParams::$comparePeriod}.
	 */
	public static function seriesLabel( string $comparePeriod ): string {
		return __( 'Pageviews', 'honest-analytics' ) . ' ' . self::compareNote( $comparePeriod );
	}
}
