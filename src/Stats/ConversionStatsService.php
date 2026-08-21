<?php
/**
 * Goal and funnel reports.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

use HonestAnalytics\Goals\FunnelsService;
use HonestAnalytics\Goals\GoalsService;
use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What converted, and where people stopped.
 */
final class ConversionStatsService {

	private GoalsService $goals;
	private FunnelsService $funnels;

	/**
	 * @param GoalsService   $goals   Goals service.
	 * @param FunnelsService $funnels Funnels service.
	 */
	public function __construct( GoalsService $goals, FunnelsService $funnels ) {
		$this->goals   = $goals;
		$this->funnels = $funnels;
	}

	/**
	 * Conversions per goal.
	 *
	 * Every enabled goal appears, including the ones that converted nothing: a
	 * goal sitting at zero is a real answer, and usually means the target is
	 * wrong.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function goals( int $siteId, DateRange $range ): array {
		global $wpdb;

		$table = Tables::name( Tables::GOALS_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT goalId, COALESCE(SUM(conversions),0) AS conversions, COALESCE(SUM(value),0) AS value
				FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s GROUP BY goalId",
				$siteId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$byGoal = [];

		foreach ( (array) $rows as $row ) {
			$byGoal[ (int) $row['goalId'] ] = $row;
		}

		$sessions = $this->sessions( $siteId, $range );
		$out      = [];

		foreach ( $this->goals->enabled() as $goal ) {
			$row         = $byGoal[ $goal->id ] ?? [];
			$conversions = (int) ( $row['conversions'] ?? 0 );

			$out[] = [
				'id'          => $goal->id,
				'name'        => $goal->name,
				'handle'      => $goal->handle,
				'type'        => $goal->goalType()->label(),
				'conversions' => $conversions,
				'value'       => (float) ( $row['value'] ?? 0 ),
				// Conversions over sessions, because a goal converts once per
				// session: somebody who reloads the thank-you page three times
				// converted once.
				'rate'        => $sessions > 0 ? $conversions / $sessions * 100 : 0.0,
			];
		}

		usort( $out, static fn ( array $a, array $b ): int => $b['conversions'] <=> $a['conversions'] );

		return $out;
	}

	/**
	 * The steps of one funnel.
	 *
	 * @param int       $siteId   Site ID.
	 * @param DateRange $range    Period.
	 * @param int       $funnelId Funnel id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function funnel( int $siteId, DateRange $range, int $funnelId ): array {
		global $wpdb;

		$funnel = $this->funnels->byId( $funnelId );

		if ( null === $funnel ) {
			return [];
		}

		$table = Tables::name( Tables::FUNNEL_STEP_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT position, COALESCE(SUM(sessions),0) AS sessions
				FROM `$table` WHERE siteId = %d AND funnelId = %d AND date BETWEEN %s AND %s
				GROUP BY position ORDER BY position ASC",
				$siteId,
				$funnelId,
				$range->from,
				$range->to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = [];

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['position'] ] = (int) $row['sessions'];
		}

		$byHandle = $this->goals->byHandle();
		$first    = $counts[1] ?? 0;
		$previous = null;
		$steps    = [];

		foreach ( $funnel->steps as $index => $handle ) {
			$position = $index + 1;
			$sessions = $counts[ $position ] ?? 0;
			$goal     = $byHandle[ $handle ] ?? null;

			$dropOff = null === $previous ? 0 : max( 0, $previous - $sessions );

			$steps[] = [
				'position'    => $position,
				'handle'      => $handle,
				'name'        => null !== $goal ? $goal->name : $handle,
				'sessions'    => $sessions,
				'dropOff'     => $dropOff,
				'dropOffRate' => ( null !== $previous && $previous > 0 ) ? $dropOff / $previous * 100 : 0.0,
				// Proportional to the first step rather than to the previous
				// one, so the bars read as a funnel rather than as a series of
				// unrelated percentages.
				'ofFirst'     => $first > 0 ? $sessions / $first * 100 : 0.0,
			];

			$previous = $sessions;
		}

		return $steps;
	}

	/**
	 * Sessions in a period: the denominator for every conversion rate.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 */
	public function sessions( int $siteId, DateRange $range ): int {
		global $wpdb;

		$table = Tables::name( Tables::SESSIONS_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(sessions),0) FROM `$table` WHERE siteId = %d AND date BETWEEN %s AND %s",
				$siteId,
				$range->from,
				$range->to
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
