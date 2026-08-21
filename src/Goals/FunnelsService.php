<?php
/**
 * Reading and writing funnel definitions.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Goals;

use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The funnel definitions, loaded once per request.
 *
 * Steps are stored as goal ids and handed out as goal handles, because a handle
 * is what a session carries: the drain records which goals converted by name,
 * long before anything knows which funnels exist.
 */
final class FunnelsService {

	/**
	 * The prefix a step gets when its goal has been deleted.
	 *
	 * It cannot collide with a real handle - those start with a letter - so a
	 * broken step stops the walk instead of silently matching something else.
	 */
	private const MISSING_PREFIX = '#deleted-';

	private GoalsService $goals;

	/**
	 * The loaded definitions, in display order.
	 *
	 * @var Funnel[]|null
	 */
	private ?array $funnels = null;

	/**
	 * @param GoalsService $goals Goals service.
	 */
	public function __construct( GoalsService $goals ) {
		$this->goals = $goals;
	}

	/**
	 * Every funnel, enabled or not.
	 *
	 * @return Funnel[]
	 */
	public function all(): array {
		if ( null === $this->funnels ) {
			$this->funnels = $this->load();
		}

		return $this->funnels;
	}

	/**
	 * The funnels being measured.
	 *
	 * @return Funnel[]
	 */
	public function enabled(): array {
		return array_values( array_filter( $this->all(), static fn ( Funnel $funnel ): bool => $funnel->enabled ) );
	}

	/**
	 * One funnel by row id.
	 *
	 * @param int $id Row id.
	 */
	public function byId( int $id ): ?Funnel {
		foreach ( $this->all() as $funnel ) {
			if ( $funnel->id === $id ) {
				return $funnel;
			}
		}

		return null;
	}

	/**
	 * One funnel by handle.
	 *
	 * @param string $handle Handle.
	 */
	public function byHandle( string $handle ): ?Funnel {
		foreach ( $this->all() as $funnel ) {
			if ( $funnel->handle === $handle ) {
				return $funnel;
			}
		}

		return null;
	}

	/**
	 * Create or update a funnel and its steps.
	 *
	 * @param Funnel $funnel The funnel. Its id is filled in when a row is created.
	 *
	 * @return array<string,string> Errors keyed by field; empty on success.
	 */
	public function save( Funnel $funnel ): array {
		global $wpdb;

		$errors = $funnel->validate();

		if ( [] !== $errors ) {
			return $errors;
		}

		$existing = $this->byHandle( $funnel->handle );

		if ( null !== $existing && $existing->id !== $funnel->id ) {
			return [ 'handle' => __( 'Another funnel already uses that handle.', 'honest-analytics' ) ];
		}

		$byHandle = $this->goals->byHandle();
		$goalIds  = [];

		foreach ( $funnel->steps as $handle ) {
			$goal = $byHandle[ $handle ] ?? null;

			if ( null === $goal || $goal->id <= 0 ) {
				return [
					'steps' => sprintf(
						/* translators: %s: goal handle. */
						__( 'There is no goal with the handle %s.', 'honest-analytics' ),
						$handle
					),
				];
			}

			$goalIds[] = $goal->id;
		}

		$table = Tables::name( Tables::FUNNELS );
		$steps = Tables::name( Tables::FUNNEL_STEPS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = [
			'name'        => trim( $funnel->name ),
			'handle'      => $funnel->handle,
			'enabled'     => $funnel->enabled ? 1 : 0,
			'sortOrder'   => $funnel->sortOrder,
			'dateUpdated' => $now,
		];

		$formats = [ '%s', '%s', '%d', '%d', '%s' ];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			if ( $funnel->id > 0 ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->update( $table, $data, [ 'id' => $funnel->id ], $formats, [ '%d' ] );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} else {
				$data['dateCreated'] = $now;
				$formats[]           = '%s';

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->insert( $table, $data, $formats );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				$funnel->id = (int) $wpdb->insert_id;
			}

			if ( $funnel->id <= 0 ) {
				throw new \RuntimeException( 'The funnel row could not be written.' );
			}

			// Replaced wholesale rather than reconciled: positions are the
			// primary key of a step, so editing them in place turns every
			// reorder into a collision with a row that has not moved yet.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->delete( $steps, [ 'funnelId' => $funnel->id ], [ '%d' ] );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $goalIds as $index => $goalId ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->insert(
					$steps,
					[
						'funnelId' => $funnel->id,
						'goalId'   => $goalId,
						'position' => $index + 1,
					],
					[ '%d', '%d', '%d' ]
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'COMMIT' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			Log::error( 'Could not save the funnel ' . $funnel->handle . ': ' . $e->getMessage() );

			$this->flush();

			return [ 'steps' => __( 'The funnel could not be saved. The error log has the details.', 'honest-analytics' ) ];
		}

		$this->flush();

		return [];
	}

	/**
	 * Delete a funnel and its steps.
	 *
	 * The rollup rows it produced are left alone: they are the site's data, and
	 * deleting a definition should not rewrite history.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->delete( Tables::name( Tables::FUNNEL_STEPS ), [ 'funnelId' => $id ], [ '%d' ] );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$deleted = $wpdb->delete( Tables::name( Tables::FUNNELS ), [ 'id' => $id ], [ '%d' ] );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->flush();

		return (bool) $deleted;
	}

	/**
	 * Forget the memoised definitions.
	 */
	public function flush(): void {
		$this->funnels = null;
	}

	/**
	 * Read the definitions and their steps.
	 *
	 * @return Funnel[]
	 */
	private function load(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}

		$table = Tables::name( Tables::FUNNELS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY sortOrder ASC, name ASC, id ASC", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( [] === (array) $rows ) {
			return [];
		}

		$steps = $this->loadSteps();
		$out   = [];

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			$out[] = new Funnel(
				$id,
				isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '',
				isset( $row['handle'] ) && is_scalar( $row['handle'] ) ? (string) $row['handle'] : '',
				! isset( $row['enabled'] ) || (bool) $row['enabled'],
				isset( $row['sortOrder'] ) ? (int) $row['sortOrder'] : 0,
				$steps[ $id ] ?? []
			);
		}

		return $out;
	}

	/**
	 * The step handles of every funnel, keyed by funnel id.
	 *
	 * A left join, so a step whose goal has been deleted keeps its position
	 * instead of vanishing - the alternative silently shortens the funnel and
	 * reports a completion rate for a walk nobody configured.
	 *
	 * @return array<int,string[]>
	 */
	private function loadSteps(): array {
		global $wpdb;

		$steps = Tables::name( Tables::FUNNEL_STEPS );
		$goals = Tables::name( Tables::GOALS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			"SELECT s.funnelId, s.position, s.goalId, g.handle
			FROM `$steps` s LEFT JOIN `$goals` g ON g.id = s.goalId
			ORDER BY s.funnelId ASC, s.position ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$funnelId = isset( $row['funnelId'] ) ? (int) $row['funnelId'] : 0;
			$handle   = isset( $row['handle'] ) && is_scalar( $row['handle'] ) ? (string) $row['handle'] : '';

			if ( '' === $handle ) {
				$handle = self::MISSING_PREFIX . (int) ( $row['goalId'] ?? 0 );
			}

			$out[ $funnelId ][] = $handle;
		}

		return $out;
	}
}
