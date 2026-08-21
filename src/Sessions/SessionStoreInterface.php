<?php
/**
 * Session storage contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hot layer where visits live while they are still happening.
 */
interface SessionStoreInterface {

	/**
	 * Read a session.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public function get( int $siteId, string $sessionKey ): ?Session;

	/**
	 * Write a session.
	 *
	 * @param Session $session Session.
	 */
	public function save( Session $session ): void;

	/**
	 * Delete a session.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public function delete( int $siteId, string $sessionKey ): void;

	/**
	 * Fold a batch's activity into a session, creating or restarting it as needed.
	 *
	 * Must be idempotent per batch: a replayed batch has to leave the session
	 * exactly as the first attempt did.
	 *
	 * @param SessionDelta $delta   Activity.
	 * @param string       $batchId Batch identifier.
	 */
	public function apply( SessionDelta $delta, string $batchId ): Session;

	/**
	 * Sessions that have gone quiet and are ready to be committed.
	 *
	 * @param int $now Current timestamp.
	 *
	 * @return Session[]
	 */
	public function idleSessions( int $now ): array;

	/**
	 * Sessions still in progress, for the real-time report.
	 *
	 * @param int $siteId Site ID.
	 * @param int $now    Current timestamp.
	 * @param int $limit  Maximum to return.
	 *
	 * @return Session[]
	 */
	public function activeSessions( int $siteId, int $now, int $limit = 500 ): array;

	/**
	 * How many sessions are in progress.
	 *
	 * @param int $siteId Site ID.
	 * @param int $now    Current timestamp.
	 */
	public function activeCount( int $siteId, int $now ): int;

	/**
	 * The store name, for diagnostics.
	 */
	public function name(): string;
}
