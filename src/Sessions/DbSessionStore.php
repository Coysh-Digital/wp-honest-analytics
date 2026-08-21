<?php
/**
 * Database session store.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sessions in a table, for the majority of sites with no persistent cache.
 *
 * This is not a fallback in the sense of "worse". Two things make it the better
 * behaved of the two stores: the real-time report becomes one indexed query
 * rather than a walk over a cache index, and `apply()` can run inside the same
 * transaction as the rollup write, so a replayed batch cannot leave a session
 * and its counters disagreeing.
 *
 * What it costs is a row read and write per hit during the drain - which runs
 * every few minutes in the background, not on anybody's pageview.
 */
final class DbSessionStore implements SessionStoreInterface {

	private Settings $settings;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Read a session.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public function get( int $siteId, string $sessionKey ): ?Session {
		global $wpdb;

		$table = Tables::name( Tables::SESSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT data FROM `$table` WHERE siteId = %d AND sessionKey = %s",
				$siteId,
				$sessionKey
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || empty( $row['data'] ) ) {
			return null;
		}

		$data = json_decode( (string) $row['data'], true );

		return is_array( $data ) ? Session::fromArray( $data ) : null;
	}

	/**
	 * The condition for "this visit has not been committed yet".
	 *
	 * `$wpdb->prepare()` renders a PHP null as an empty string for a %s
	 * placeholder - there is no way to make it emit a literal NULL. So a
	 * session that has never been closed stores '' rather than NULL, and a
	 * plain `IS NULL` silently matches nothing. Both forms are accepted here so
	 * the store behaves the same whichever way a row was written.
	 */
	private const OPEN_CONDITION = "( closedByBatch IS NULL OR closedByBatch = '' )";

	/**
	 * Write a session.
	 *
	 * @param Session $session Session.
	 */
	public function save( Session $session ): void {
		global $wpdb;

		$table = Tables::name( Tables::SESSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$table`
					(siteId, sessionKey, visitorHash, startedAt, lastSeenAt, pageviews, lastBatch, closedByBatch, data)
					VALUES (%d, %s, %s, %d, %d, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					visitorHash = VALUES(visitorHash),
					startedAt = VALUES(startedAt),
					lastSeenAt = VALUES(lastSeenAt),
					pageviews = VALUES(pageviews),
					lastBatch = VALUES(lastBatch),
					closedByBatch = VALUES(closedByBatch),
					data = VALUES(data)",
				$session->siteId,
				$session->sessionKey,
				$session->visitorHash,
				$session->startedAt,
				$session->lastSeenAt,
				$session->pageviews,
				$session->lastBatch,
				$session->closedByBatch,
				(string) wp_json_encode( $session->toArray() )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete a session.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public function delete( int $siteId, string $sessionKey ): void {
		global $wpdb;

		$table = Tables::name( Tables::SESSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM `$table` WHERE siteId = %d AND sessionKey = %s", $siteId, $sessionKey )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fold a batch's activity into a session.
	 *
	 * @param SessionDelta $delta   Activity.
	 * @param string       $batchId Batch identifier.
	 */
	public function apply( SessionDelta $delta, string $batchId ): Session {
		$existing = $this->get( $delta->siteId, $delta->sessionKey );

		$session = SessionMerger::apply( $existing, $delta, $batchId, $this->settings->sessionWindowSeconds() );

		$this->save( $session );

		return $session;
	}

	/**
	 * Sessions that have gone quiet.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return Session[]
	 */
	public function idleSessions( int $now ): array {
		global $wpdb;

		$table  = Tables::name( Tables::SESSIONS );
		$cutoff = $now - $this->settings->sessionWindowSeconds();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT data FROM `$table` WHERE lastSeenAt <= %d ORDER BY lastSeenAt ASC LIMIT 5000",
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->hydrate( (array) $rows );
	}

	/**
	 * Sessions still in progress.
	 *
	 * @param int $siteId Site ID.
	 * @param int $now    Current timestamp.
	 * @param int $limit  Maximum to return.
	 *
	 * @return Session[]
	 */
	public function activeSessions( int $siteId, int $now, int $limit = 500 ): array {
		global $wpdb;

		$table  = Tables::name( Tables::SESSIONS );
		$cutoff = $now - $this->settings->sessionWindowSeconds();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT data FROM `$table`
				WHERE siteId = %d AND lastSeenAt > %d AND " . self::OPEN_CONDITION . '
				ORDER BY lastSeenAt DESC LIMIT %d',
				$siteId,
				$cutoff,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $this->hydrate( (array) $rows );
	}

	/**
	 * How many sessions are in progress.
	 *
	 * @param int $siteId Site ID.
	 * @param int $now    Current timestamp.
	 */
	public function activeCount( int $siteId, int $now ): int {
		global $wpdb;

		$table  = Tables::name( Tables::SESSIONS );
		$cutoff = $now - $this->settings->sessionWindowSeconds();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table` WHERE siteId = %d AND lastSeenAt > %d AND " . self::OPEN_CONDITION,
				$siteId,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The store name.
	 */
	public function name(): string {
		return 'db';
	}

	/**
	 * Turn rows into sessions.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 *
	 * @return Session[]
	 */
	private function hydrate( array $rows ): array {
		$sessions = [];

		foreach ( $rows as $row ) {
			$data = json_decode( (string) ( $row['data'] ?? '' ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$session = Session::fromArray( $data );

			if ( null !== $session ) {
				$sessions[] = $session;
			}
		}

		return $sessions;
	}
}
