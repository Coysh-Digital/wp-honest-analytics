<?php
/**
 * Reading and writing goal definitions.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Goals;

use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The goal definitions, loaded once per request.
 *
 * The drain asks for these on every batch, so they are memoised for the length
 * of the request and deliberately not cached beyond it: a definition changed in
 * the admin has to take effect on the very next batch, and a stale copy would
 * keep matching a path the site owner has already corrected.
 *
 * There are a handful of rows. One small query is the right price.
 */
final class GoalsService {

	/**
	 * The loaded definitions, in display order.
	 *
	 * @var Goal[]|null
	 */
	private ?array $goals = null;

	/**
	 * Every goal, enabled or not.
	 *
	 * @return Goal[]
	 */
	public function all(): array {
		if ( null === $this->goals ) {
			$this->goals = $this->load();
		}

		return $this->goals;
	}

	/**
	 * The goals being measured.
	 *
	 * @return Goal[]
	 */
	public function enabled(): array {
		return array_values( array_filter( $this->all(), static fn ( Goal $goal ): bool => $goal->enabled ) );
	}

	/**
	 * The goals decided from a single hit.
	 *
	 * @return Goal[]
	 */
	public function live(): array {
		return array_values( array_filter( $this->enabled(), static fn ( Goal $goal ): bool => $goal->isLive() ) );
	}

	/**
	 * Every goal, keyed by handle.
	 *
	 * @return array<string,Goal>
	 */
	public function byHandle(): array {
		$out = [];

		foreach ( $this->all() as $goal ) {
			$out[ $goal->handle ] = $goal;
		}

		return $out;
	}

	/**
	 * One goal by row id.
	 *
	 * @param int $id Row id.
	 */
	public function byId( int $id ): ?Goal {
		foreach ( $this->all() as $goal ) {
			if ( $goal->id === $id ) {
				return $goal;
			}
		}

		return null;
	}

	/**
	 * Create or update a goal.
	 *
	 * @param Goal $goal The goal. Its id is filled in when a row is created.
	 *
	 * @return array<string,string> Errors keyed by field; empty on success.
	 */
	public function save( Goal $goal ): array {
		global $wpdb;

		$errors = $goal->validate();

		if ( [] !== $errors ) {
			return $errors;
		}

		$existing = $this->byHandle()[ $goal->handle ] ?? null;

		if ( null !== $existing && $existing->id !== $goal->id ) {
			$errors['handle'] = __( 'Another goal already uses that handle.', 'honest-analytics' );

			return $errors;
		}

		$table = Tables::name( Tables::GOALS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$data = [
			'name'        => trim( $goal->name ),
			'handle'      => $goal->handle,
			'type'        => $goal->goalType()->value,
			'target'      => trim( $goal->target ),
			'value'       => $goal->value,
			'enabled'     => $goal->enabled ? 1 : 0,
			'sortOrder'   => $goal->sortOrder,
			'dateUpdated' => $now,
		];

		$formats = [ '%s', '%s', '%s', '%s', '%f', '%d', '%d', '%s' ];

		if ( $goal->id > 0 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->update( $table, $data, [ 'id' => $goal->id ], $formats, [ '%d' ] );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$data['dateCreated'] = $now;
			$formats[]           = '%s';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->insert( $table, $data, $formats );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$goal->id = (int) $wpdb->insert_id;
		}

		$this->flush();

		return [];
	}

	/**
	 * Delete a goal.
	 *
	 * The rollup rows it already produced are left alone: they are the site's
	 * data, and deleting a definition should not rewrite history. Funnel steps
	 * naming it are left alone too, so a funnel that has lost a step reports
	 * the drop-off at that step rather than quietly measuring a shorter walk.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$table = Tables::name( Tables::GOALS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->flush();

		return (bool) $deleted;
	}

	/**
	 * Forget the memoised definitions.
	 */
	public function flush(): void {
		$this->goals = null;
	}

	/**
	 * Read the definitions.
	 *
	 * @return Goal[]
	 */
	private function load(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}

		$table = Tables::name( Tables::GOALS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY sortOrder ASC, name ASC, id ASC", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Goal::fromRow( $row );
			}
		}

		return $out;
	}
}
