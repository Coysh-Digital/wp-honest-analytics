<?php
/**
 * The real-time snapshot.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

use HonestAnalytics\Sessions\SessionStoreInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who is on the site right now.
 *
 * Read entirely from the session layer, so this screen runs no report queries
 * at all - which is what makes it safe to poll every fifteen seconds.
 *
 * A row here is a visit, not a person. There is no address, no name, and no
 * identifier that outlives the visit: somebody who leaves and comes back
 * tomorrow is a new row with no way to know it was them. That is the salt
 * rotation, and it is what keeps this screen cookieless.
 */
final class RealtimeService {

	private SessionStoreInterface $sessions;

	/**
	 * @param SessionStoreInterface $sessions Session store.
	 */
	public function __construct( SessionStoreInterface $sessions ) {
		$this->sessions = $sessions;
	}

	/**
	 * The current picture.
	 *
	 * @param int      $siteId Site ID.
	 * @param int|null $now    Timestamp.
	 * @param int      $limit  Maximum visits listed.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot( int $siteId, ?int $now = null, int $limit = 50 ): array {
		$now      = $now ?? time();
		$sessions = $this->sessions->activeSessions( $siteId, $now, max( $limit, 200 ) );

		$pageviews = 0;
		$byPath    = [];
		$active    = [];

		foreach ( $sessions as $session ) {
			$pageviews += $session->pageviews;

			$path = '' !== $session->lastPath ? $session->lastPath : '/';

			$byPath[ $path ] = ( $byPath[ $path ] ?? 0 ) + 1;

			$active[] = [
				'currentPath' => $path,
				'entryPath'   => '' !== $session->entryPath ? $session->entryPath : $path,
				'pageviews'   => $session->pageviews,
				'durationMs'  => $session->durationMs(),
				'secondsAgo'  => max( 0, $now - $session->lastSeenAt ),
				'isNew'       => $session->pageviews <= 1,
			];
		}

		usort( $active, static fn ( array $a, array $b ): int => $a['secondsAgo'] <=> $b['secondsAgo'] );

		arsort( $byPath );

		$pages = [];

		foreach ( array_slice( $byPath, 0, 10, true ) as $path => $count ) {
			$pages[] = [
				'path'     => (string) $path,
				'visitors' => (int) $count,
			];
		}

		$visitors = count( $sessions );

		return [
			'visitors'        => $visitors,
			'pageviews'       => $pageviews,
			'pagesPerVisitor' => $visitors > 0 ? round( $pageviews / $visitors, 1 ) : 0.0,
			'pages'           => $pages,
			'active'          => array_slice( $active, 0, max( 1, $limit ) ),
			'truncated'       => max( 0, $visitors - $limit ),
			'generatedAt'     => $now,
		];
	}

	/**
	 * Just the visitor count, for the dashboard banner.
	 *
	 * @param int      $siteId Site ID.
	 * @param int|null $now    Timestamp.
	 */
	public function visitorCount( int $siteId, ?int $now = null ): int {
		return $this->sessions->activeCount( $siteId, $now ?? time() );
	}
}
