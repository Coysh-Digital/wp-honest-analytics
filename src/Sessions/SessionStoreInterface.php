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
	 * Fold a whole batch of deltas in, one session each.
	 *
	 * The same contract as apply(), called once per drain chunk. A store that
	 * keeps a shared index can read and write it once here rather than once
	 * per session; a store that does not simply loops.
	 *
	 * @param SessionDelta[] $deltas  Activity, one delta per session.
	 * @param string         $batchId Batch identifier.
	 */
	public function applyBatch( array $deltas, string $batchId ): void;

	/**
	 * Read several sessions at once.
	 *
	 * The drain asks for every session in a chunk twice - once to find the
	 * referrer each visit arrived by, and once to merge the chunk in - and did
	 * it one key at a time. On the database store that is two queries per
	 * distinct session per chunk; one `IN (...)` answers the same question.
	 *
	 * Keys absent from the store are absent from the result rather than null,
	 * so a caller can tell "not there" from "there and empty".
	 *
	 * @param int      $siteId      Site ID.
	 * @param string[] $sessionKeys Session keys.
	 *
	 * @return array<string,Session> Keyed by session key.
	 */
	public function getMany( int $siteId, array $sessionKeys ): array;

	/**
	 * Write several sessions at once.
	 *
	 * The cache store rewrites a whole site index per `save()`, so closing a
	 * few thousand idle sessions one at a time is quadratic and never
	 * finishes. Both stores implement this; only one of them needed it.
	 *
	 * @param Session[] $sessions Sessions to write.
	 */
	public function saveMany( array $sessions ): void;

	/**
	 * Delete several sessions at once.
	 *
	 * @param Session[] $sessions Sessions to remove.
	 */
	public function deleteMany( array $sessions ): void;

	/**
	 * Sessions that have gone quiet.
	 *
	 * Bounded by the implementation. An unbounded answer is a drain that never
	 * finishes and therefore counts nothing at all.
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
