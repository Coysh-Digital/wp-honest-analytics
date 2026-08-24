<?php
/**
 * Object-cache session store.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sessions in Redis or Memcached, for sites that have one.
 *
 * Two keys per site: one per session, and an index mapping session keys to when
 * they were last seen. The index is what makes "which visits have gone quiet"
 * answerable without scanning, and it is deliberately stored without an expiry:
 * an expired key cannot be read, so a session left to lapse on its own would
 * take its counters with it. Closure is driven by the index, never by expiry.
 *
 * Two failure modes are worth knowing about. Memcached refuses values over a
 * megabyte, which the index reaches at roughly twenty thousand concurrent
 * visits - the write is checked and the store steps aside rather than losing
 * the lot. And an APCu-backed drop-in reports itself as persistent while being
 * per-server, so on multi-server hosting the sessions on one machine are
 * invisible to the next; the settings screen names that case and recommends the
 * database store.
 */
final class CacheSessionStore implements SessionStoreInterface {

	private const SESSION_PREFIX = 'session:';
	private const INDEX_PREFIX   = 'session-index:';

	/** A safety net only. The index, not expiry, is what closes a session. */
	private const TTL_PADDING = 86400;

	/**
	 * The most idle sessions one run will close, matching the database store.
	 *
	 * A cap is what makes the close bounded work. Above it the drain never
	 * finishes and nothing is counted at all.
	 */
	private const IDLE_LIMIT = 5000;

	private Settings $settings;
	private KeyValueStoreInterface $store;

	/**
	 * @param Settings                    $settings Settings.
	 * @param KeyValueStoreInterface|null $store    Store.
	 */
	public function __construct( Settings $settings, ?KeyValueStoreInterface $store = null ) {
		$this->settings = $settings;
		$this->store    = $store ?? StoreFactory::keyValue();
	}

	/**
	 * Read a session.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public function get( int $siteId, string $sessionKey ): ?Session {
		$data = $this->store->get( self::sessionKey( $siteId, $sessionKey ) );

		return is_array( $data ) ? Session::fromArray( $data ) : null;
	}

	/**
	 * Write a session and note it in the index.
	 *
	 * @param Session $session Session.
	 */
	public function save( Session $session ): void {
		$ttl = $this->settings->sessionWindowSeconds() + self::TTL_PADDING;

		$this->store->set( self::sessionKey( $session->siteId, $session->sessionKey ), $session->toArray(), $ttl );

		$index = $this->index( $session->siteId );

		$index[ $session->sessionKey ] = $session->lastSeenAt;

		$this->writeIndex( $session->siteId, $index );
	}

	/**
	 * Delete a session and forget it.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public function delete( int $siteId, string $sessionKey ): void {
		$this->store->delete( self::sessionKey( $siteId, $sessionKey ) );

		$index = $this->index( $siteId );

		if ( isset( $index[ $sessionKey ] ) ) {
			unset( $index[ $sessionKey ] );

			$this->writeIndex( $siteId, $index );
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
	 * Fold a batch in with one read and one write of the index.
	 *
	 * Through apply() each session would re-read the site's index, add one
	 * entry and write the whole thing back - a megabyte at twenty thousand
	 * concurrent visits, sent to the cache once per session in the chunk.
	 * That is the drain spending its time serialising the same array a few
	 * thousand times over. Here the sessions are written as they are merged
	 * and the index follows once, so a crash in between costs at most this
	 * batch's entries, which is the same exposure one apply() already had.
	 *
	 * @param SessionDelta[] $deltas  Activity.
	 * @param string         $batchId Batch identifier.
	 */
	public function applyBatch( array $deltas, string $batchId ): void {
		if ( [] === $deltas ) {
			return;
		}

		$ttl     = $this->settings->sessionWindowSeconds() + self::TTL_PADDING;
		$indexes = [];

		foreach ( $deltas as $delta ) {
			$existing = $this->get( $delta->siteId, $delta->sessionKey );
			$session  = SessionMerger::apply( $existing, $delta, $batchId, $this->settings->sessionWindowSeconds() );

			$this->store->set( self::sessionKey( $session->siteId, $session->sessionKey ), $session->toArray(), $ttl );

			if ( ! isset( $indexes[ $session->siteId ] ) ) {
				$indexes[ $session->siteId ] = $this->index( $session->siteId );
			}

			$indexes[ $session->siteId ][ $session->sessionKey ] = $session->lastSeenAt;
		}

		foreach ( $indexes as $siteId => $index ) {
			$this->writeIndex( (int) $siteId, $index );
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
		$cutoff   = $now - $this->settings->sessionWindowSeconds();
		$sessions = [];

		foreach ( $this->siteIds() as $siteId ) {
			$index   = $this->index( $siteId );
			$changed = false;

			// Oldest first, and capped, to match the database store. Unbounded,
			// this returned every idle session on every site at once - and the
			// drain then wrote each one back individually, rewriting the whole
			// site index each time. Twenty thousand idle sessions against a
			// megabyte of index is forty thousand full index round trips in one
			// run, which never finishes; the next run starts over, and
			// GcService deletes the lot after a day having counted none of it.
			// A day of sources, devices, geo, campaigns, goals, entry and exit
			// pages and bounces, gone.
			asort( $index );

			foreach ( $index as $sessionKey => $lastSeen ) {
				if ( (int) $lastSeen > $cutoff ) {
					// Sorted, so everything after this is newer still.
					break;
				}

				if ( count( $sessions ) >= self::IDLE_LIMIT ) {
					break;
				}

				$session = $this->get( $siteId, (string) $sessionKey );

				if ( null === $session ) {
					// Evicted under memory pressure, or expired on its own. The
					// index entry is all that is left; drop it rather than
					// carrying a pointer to nothing forever.
					unset( $index[ $sessionKey ] );
					$changed = true;

					continue;
				}

				$sessions[] = $session;
			}

			if ( $changed ) {
				$this->writeIndex( $siteId, $index );
			}

			if ( count( $sessions ) >= self::IDLE_LIMIT ) {
				break;
			}
		}

		return $sessions;
	}

	/**
	 * Read several sessions.
	 *
	 * One cache read apiece: there is no shared row to fetch in a single go
	 * the way the database store has, so this exists to satisfy the contract
	 * rather than to save anything here.
	 *
	 * @param int      $siteId      Site ID.
	 * @param string[] $sessionKeys Session keys.
	 *
	 * @return array<string,Session>
	 */
	public function getMany( int $siteId, array $sessionKeys ): array {
		$out = [];

		foreach ( array_unique( $sessionKeys ) as $sessionKey ) {
			$session = $this->get( $siteId, $sessionKey );

			if ( null !== $session ) {
				$out[ $sessionKey ] = $session;
			}
		}

		return $out;
	}

	/**
	 * Write several sessions, touching each site's index once.
	 *
	 * `save()` reads and rewrites the whole index for the site it belongs to,
	 * which is the right shape for one session and quadratic for a batch of
	 * them. This is the same work with the index written once per site.
	 *
	 * @param Session[] $sessions Sessions to write.
	 */
	public function saveMany( array $sessions ): void {
		$this->writeMany( $sessions, false );
	}

	/**
	 * Delete several sessions, touching each site's index once.
	 *
	 * @param Session[] $sessions Sessions to remove.
	 */
	public function deleteMany( array $sessions ): void {
		$this->writeMany( $sessions, true );
	}

	/**
	 * The shared body of saveMany() and deleteMany().
	 *
	 * @param Session[] $sessions Sessions.
	 * @param bool      $remove   Whether to delete rather than write.
	 */
	private function writeMany( array $sessions, bool $remove ): void {
		$bySite = [];

		foreach ( $sessions as $session ) {
			$bySite[ $session->siteId ][] = $session;
		}

		foreach ( $bySite as $siteId => $group ) {
			$index = $this->index( $siteId );

			foreach ( $group as $session ) {
				if ( $remove ) {
					$this->store->delete( self::sessionKey( $session->siteId, $session->sessionKey ) );

					unset( $index[ $session->sessionKey ] );

					continue;
				}

				$this->store->set(
					self::sessionKey( $session->siteId, $session->sessionKey ),
					$session->toArray(),
					$this->settings->sessionWindowSeconds() + self::TTL_PADDING
				);

				$index[ $session->sessionKey ] = $session->lastSeenAt;
			}

			$this->writeIndex( $siteId, $index );
		}
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
		$cutoff = $now - $this->settings->sessionWindowSeconds();
		$index  = $this->index( $siteId );

		arsort( $index );

		$sessions = [];

		foreach ( $index as $sessionKey => $lastSeen ) {
			if ( count( $sessions ) >= $limit ) {
				break;
			}

			if ( (int) $lastSeen <= $cutoff ) {
				continue;
			}

			$session = $this->get( $siteId, (string) $sessionKey );

			if ( null !== $session && null === $session->closedByBatch ) {
				$sessions[] = $session;
			}
		}

		return $sessions;
	}

	/**
	 * How many sessions are in progress.
	 *
	 * @param int $siteId Site ID.
	 * @param int $now    Current timestamp.
	 */
	public function activeCount( int $siteId, int $now ): int {
		$cutoff = $now - $this->settings->sessionWindowSeconds();
		$count  = 0;

		foreach ( $this->index( $siteId ) as $lastSeen ) {
			if ( (int) $lastSeen > $cutoff ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * The store name.
	 */
	public function name(): string {
		return 'cache';
	}

	/**
	 * The index for a site.
	 *
	 * @param int $siteId Site ID.
	 *
	 * @return array<string,int>
	 */
	private function index( int $siteId ): array {
		$index = $this->store->get( self::INDEX_PREFIX . $siteId );

		if ( ! is_array( $index ) ) {
			return [];
		}

		$out = [];

		foreach ( $index as $key => $lastSeen ) {
			if ( is_string( $key ) && is_numeric( $lastSeen ) ) {
				$out[ $key ] = (int) $lastSeen;
			}
		}

		return $out;
	}

	/**
	 * Write the index back, stepping aside if the backend refuses it.
	 *
	 * @param int               $siteId Site ID.
	 * @param array<string,int> $index  Index.
	 */
	private function writeIndex( int $siteId, array $index ): void {
		if ( $this->store->set( self::INDEX_PREFIX . $siteId, $index, 0 ) ) {
			$this->rememberSite( $siteId );

			return;
		}

		Log::warning(
			'The session index could not be written to the object cache - it is probably over the backend\'s value limit. ' .
			'Set the session store to "db" in the settings; the cache store cannot close sessions it cannot index.'
		);
	}

	/**
	 * Note that a site has sessions, so the drain knows where to look.
	 *
	 * @param int $siteId Site ID.
	 */
	private function rememberSite( int $siteId ): void {
		$sites = $this->store->get( 'session-sites' );
		$sites = is_array( $sites ) ? array_map( 'intval', $sites ) : [];

		if ( ! in_array( $siteId, $sites, true ) ) {
			$sites[] = $siteId;
			$this->store->set( 'session-sites', $sites, 0 );
		}
	}

	/**
	 * The sites that have session indexes.
	 *
	 * @return int[]
	 */
	private function siteIds(): array {
		$sites = $this->store->get( 'session-sites' );
		$sites = is_array( $sites ) ? array_values( array_unique( array_map( 'intval', $sites ) ) ) : [];

		$current = get_current_blog_id();

		if ( ! in_array( $current, $sites, true ) ) {
			$sites[] = $current;
		}

		return $sites;
	}

	/**
	 * The store key for a session.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	private static function sessionKey( int $siteId, string $sessionKey ): string {
		return self::SESSION_PREFIX . $siteId . ':' . $sessionKey;
	}
}
