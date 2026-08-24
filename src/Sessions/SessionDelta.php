<?php
/**
 * One batch's worth of activity for one session.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

use HonestAnalytics\Capture\Hit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a drain batch learned about one visit.
 *
 * Ordering is by timestamp rather than by arrival: spool order is nearly time
 * order, and "nearly" is how a funnel ends up occasionally reporting that
 * somebody reached step three before step two.
 */
final class SessionDelta {

	public int $views = 0;
	public int $firstSeen;
	public int $lastSeen;
	public string $entryPath;
	public string $lastPath;
	public string $referrer;
	public string $device;
	public string $countryCode;
	public string $region;
	public int $maxScroll = 0;
	public ?string $visitorId;
	public ?int $userId;

	/**
	 * Campaign touches, in the order they happened.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $campaigns = [];

	/**
	 * Goal handles, in the order they converted.
	 *
	 * @var string[]
	 */
	public array $goals = [];

	private function __construct(
		public readonly int $siteId,
		public readonly string $sessionKey,
		public readonly string $visitorHash
	) {
		$this->firstSeen   = 0;
		$this->lastSeen    = 0;
		$this->entryPath   = '';
		$this->lastPath    = '';
		$this->referrer    = '';
		$this->device      = '';
		$this->countryCode = '';
		$this->region      = '';
		$this->visitorId   = null;
		$this->userId      = null;
	}

	/**
	 * Start a delta from the first hit seen for a session.
	 *
	 * @param Hit $hit The hit.
	 */
	public static function fromHit( Hit $hit ): self {
		$delta = new self( $hit->siteId, $hit->sessionKey, $hit->visitorHash );

		$delta->firstSeen   = $hit->timestamp;
		$delta->lastSeen    = $hit->timestamp;
		$delta->entryPath   = $hit->path;
		$delta->lastPath    = $hit->path;
		$delta->referrer    = $hit->referrer;
		$delta->device      = $hit->device;
		$delta->countryCode = $hit->countryCode;
		$delta->region      = $hit->region;
		$delta->views       = $hit->countView ? 1 : 0;
		$delta->maxScroll   = $hit->scrollBucket ?? 0;
		$delta->visitorId   = $hit->visitorId;
		$delta->userId      = $hit->userId;

		if ( null !== $hit->campaign ) {
			$delta->campaigns[] = $hit->campaign->toArray();
		}

		return $delta;
	}

	/**
	 * Fold another hit in.
	 *
	 * @param Hit $hit The hit.
	 */
	public function add( Hit $hit ): void {
		if ( $hit->countView ) {
			++$this->views;
		}

		if ( $hit->timestamp < $this->firstSeen || 0 === $this->firstSeen ) {
			$this->firstSeen = $hit->timestamp;
			$this->entryPath = $hit->path;

			if ( '' === $this->referrer && '' !== $hit->referrer ) {
				$this->referrer = $hit->referrer;
			}
		}

		if ( $hit->timestamp >= $this->lastSeen ) {
			$this->lastSeen = $hit->timestamp;
			$this->lastPath = $hit->path;
		}

		if ( '' === $this->device && '' !== $hit->device ) {
			$this->device = $hit->device;
		}

		// Resolved once, on arrival. A visitor does not change country mid-visit,
		// and re-resolving would mean keeping the address around to do it.
		if ( '' === $this->countryCode && '' !== $hit->countryCode ) {
			$this->countryCode = $hit->countryCode;
			$this->region      = $hit->region;
		}

		if ( null === $this->visitorId && null !== $hit->visitorId ) {
			$this->visitorId = $hit->visitorId;
			$this->userId    = $hit->userId;
		}

		$this->maxScroll = max( $this->maxScroll, $hit->scrollBucket ?? 0 );

		if ( null !== $hit->campaign ) {
			$key  = $hit->campaign->key();
			$seen = false;

			foreach ( $this->campaigns as $existing ) {
				if ( implode( '|', [ $existing['s'] ?? '', $existing['m'] ?? '', $existing['c'] ?? '', $existing['t'] ?? '', $existing['o'] ?? '' ] ) === $key ) {
					$seen = true;

					break;
				}
			}

			if ( ! $seen ) {
				$this->campaigns[] = $hit->campaign->toArray();
			}
		}
	}

	/**
	 * Note that a goal converted, once.
	 *
	 * @param string $handle Goal handle.
	 */
	public function matchGoal( string $handle ): void {
		if ( ! in_array( $handle, $this->goals, true ) ) {
			$this->goals[] = $handle;
		}
	}

	/**
	 * The key a delta is stored under while a batch is being processed.
	 *
	 * @param int    $siteId     Site ID.
	 * @param string $sessionKey Session key.
	 */
	public static function key( int $siteId, string $sessionKey ): string {
		return $siteId . ':' . $sessionKey;
	}
}
