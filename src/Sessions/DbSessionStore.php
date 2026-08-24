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
use HonestAnalytics\Support\Db;

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

		// Through Db: this runs inside the drain's transaction, and a failure
		// answered with false would let the batch marker commit beside
		// counters that were never written.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		Db::query(
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Read several sessions in one query.
	 *
	 * @param int      $siteId      Site ID.
	 * @param string[] $sessionKeys Session keys.
	 *
	 * @return array<string,Session>
	 */
	public function getMany( int $siteId, array $sessionKeys ): array {
		global $wpdb;

		$keys = array_values( array_unique( $sessionKeys ) );

		if ( [] === $keys ) {
			return [];
		}

		$table = Tables::name( Tables::SESSIONS );
		$out   = [];

		foreach ( array_chunk( $keys, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT sessionKey, data FROM `$table` WHERE siteId = %d AND sessionKey IN ($placeholders)",
					array_merge( [ $siteId ], $chunk )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || empty( $row['data'] ) ) {
					continue;
				}

				$data = json_decode( (string) $row['data'], true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				$session = Session::fromArray( $data );

				if ( $session instanceof Session ) {
					$out[ (string) $row['sessionKey'] ] = $session;
				}
			}
		}

		return $out;
	}

	/**
	 * Write several sessions.
	 *
	 * One row at a time here, because the upsert is already a single indexed
	 * statement against the primary key and batching it would mean building a
	 * multi-row VALUES list with nine columns and a longtext in each. The
	 * method exists so that the cache store, where the difference is
	 * quadratic rather than linear, can be called the same way.
	 *
	 * @param Session[] $sessions Sessions to write.
	 */
	public function saveMany( array $sessions ): void {
		foreach ( $sessions as $session ) {
			$this->save( $session );
		}
	}

	/**
	 * Delete several sessions, one statement per site.
	 *
	 * @param Session[] $sessions Sessions to remove.
	 */
	public function deleteMany( array $sessions ): void {
		global $wpdb;

		if ( [] === $sessions ) {
			return;
		}

		$table  = Tables::name( Tables::SESSIONS );
		$bySite = [];

		foreach ( $sessions as $session ) {
			$bySite[ $session->siteId ][] = $session->sessionKey;
		}

		foreach ( $bySite as $siteId => $keys ) {
			foreach ( array_chunk( $keys, 500 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `$table` WHERE siteId = %d AND sessionKey IN ($placeholders)",
						array_merge( [ $siteId ], $chunk )
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
		}
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
	 * Fold a batch in, a row read and a row write per session.
	 *
	 * @param SessionDelta[] $deltas  Activity.
	 * @param string         $batchId Batch identifier.
	 */
	public function applyBatch( array $deltas, string $batchId ): void {
		foreach ( $deltas as $delta ) {
			$this->apply( $delta, $batchId );
		}
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
