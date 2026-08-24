<?php
/**
 * A visit in progress.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A visit, held only until it closes.
 *
 * Sessions never become rows in a report table. They live in the hot layer
 * while somebody is still browsing, contribute their totals to the rollups when
 * they go idle, and are deleted. A row here is a visit, not a person: there is
 * no address, no name, and no identifier that outlives the visit.
 */
final class Session {

	/**
	 * @param int                             $siteId        Site the visit belongs to.
	 * @param string                          $sessionKey    Opaque key for this visit; not a person.
	 * @param string                          $visitorHash   Today's hash, gone when the salt rotates.
	 * @param int                             $startedAt     When the visit began.
	 * @param int                             $lastSeenAt    When it was last seen.
	 * @param int                             $pageviews     Pages viewed so far.
	 * @param string                          $entryPath     The page it started on.
	 * @param string                          $lastPath      The page it is on now.
	 * @param string                          $referrer      Referring host, never a URL.
	 * @param string                          $device        The reduced device signature; never a user agent.
	 * @param string|null                     $lastBatch     Batch that last touched the row.
	 * @param string|null                     $closedByBatch Batch that closed it, once it has.
	 * @param array<int,array<string,string>> $campaigns     One entry per campaign touch, in order.
	 * @param string                          $countryCode   Two letters, when geography is enabled.
	 * @param string                          $region        Reserved; never populated by default.
	 * @param array<int,string>               $goals         Handles of goals converted during the visit.
	 * @param int                             $maxScroll     Deepest scroll bucket reached.
	 * @param string|null                     $visitorId     Durable id, only with consent.
	 * @param int|null                        $userId        Account, only with consent.
	 */
	public function __construct(
		public int $siteId,
		public string $sessionKey,
		public string $visitorHash,
		public int $startedAt,
		public int $lastSeenAt,
		public int $pageviews = 0,
		public string $entryPath = '',
		public string $lastPath = '',
		public string $referrer = '',
		public string $device = '',
		public ?string $lastBatch = null,
		public ?string $closedByBatch = null,
		public array $campaigns = [],
		public string $countryCode = '',
		public string $region = '',
		public array $goals = [],
		public int $maxScroll = 0,
		public ?string $visitorId = null,
		public ?int $userId = null
	) {
	}

	/**
	 * Whether the visit was a single page.
	 *
	 * A dwell-only beacon keeps the session alive but adds no pageview, so it
	 * cannot turn a bounce into a non-bounce - which is right: somebody who read
	 * one page for four minutes and left still only saw one page.
	 */
	public function isBounce(): bool {
		return $this->pageviews <= 1;
	}

	/**
	 * How long the visit lasted, in milliseconds.
	 *
	 * Derived from hit timestamps, so it has second resolution. This is a
	 * different measurement from time on page, which comes from the beacon in
	 * milliseconds and answers a different question.
	 */
	public function durationMs(): int {
		return max( 0, $this->lastSeenAt - $this->startedAt ) * 1000;
	}

	/**
	 * Whether the visit has gone quiet.
	 *
	 * @param int $now           Current timestamp.
	 * @param int $sessionWindow Idle seconds allowed.
	 */
	public function hasExpired( int $now, int $sessionWindow ): bool {
		return null !== $this->closedByBatch || $this->lastSeenAt <= $now - $sessionWindow;
	}

	/**
	 * The storage representation.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return [
			'si' => $this->siteId,
			'k'  => $this->sessionKey,
			'v'  => $this->visitorHash,
			's'  => $this->startedAt,
			'l'  => $this->lastSeenAt,
			'n'  => $this->pageviews,
			'ep' => $this->entryPath,
			'lp' => $this->lastPath,
			'r'  => $this->referrer,
			'dv' => $this->device,
			'lb' => $this->lastBatch,
			'cb' => $this->closedByBatch,
			'cm' => $this->campaigns,
			'cc' => $this->countryCode,
			'rg' => $this->region,
			'g'  => $this->goals,
			'ms' => $this->maxScroll,
			'vi' => $this->visitorId,
			'ui' => $this->userId,
		];
	}

	/**
	 * Rebuild from storage.
	 *
	 * @param array<string,mixed> $data Stored array.
	 */
	public static function fromArray( array $data ): ?self {
		$key = isset( $data['k'] ) && is_scalar( $data['k'] ) ? (string) $data['k'] : '';

		if ( '' === $key ) {
			return null;
		}

		return new self(
			isset( $data['si'] ) ? (int) $data['si'] : 0,
			$key,
			isset( $data['v'] ) && is_scalar( $data['v'] ) ? (string) $data['v'] : '',
			isset( $data['s'] ) ? (int) $data['s'] : 0,
			isset( $data['l'] ) ? (int) $data['l'] : 0,
			isset( $data['n'] ) ? (int) $data['n'] : 0,
			isset( $data['ep'] ) && is_scalar( $data['ep'] ) ? (string) $data['ep'] : '',
			isset( $data['lp'] ) && is_scalar( $data['lp'] ) ? (string) $data['lp'] : '',
			isset( $data['r'] ) && is_scalar( $data['r'] ) ? (string) $data['r'] : '',
			isset( $data['dv'] ) && is_scalar( $data['dv'] ) ? (string) $data['dv'] : '',
			isset( $data['lb'] ) && is_scalar( $data['lb'] ) ? (string) $data['lb'] : null,
			isset( $data['cb'] ) && is_scalar( $data['cb'] ) ? (string) $data['cb'] : null,
			isset( $data['cm'] ) && is_array( $data['cm'] ) ? $data['cm'] : [],
			isset( $data['cc'] ) && is_scalar( $data['cc'] ) ? (string) $data['cc'] : '',
			isset( $data['rg'] ) && is_scalar( $data['rg'] ) ? (string) $data['rg'] : '',
			isset( $data['g'] ) && is_array( $data['g'] ) ? array_values( array_filter( $data['g'], 'is_string' ) ) : [],
			isset( $data['ms'] ) ? (int) $data['ms'] : 0,
			isset( $data['vi'] ) && is_scalar( $data['vi'] ) ? (string) $data['vi'] : null,
			isset( $data['ui'] ) ? (int) $data['ui'] : null
		);
	}
}
