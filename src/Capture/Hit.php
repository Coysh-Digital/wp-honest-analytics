<?php
/**
 * A single captured event.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Channels\Campaign;
use HonestAnalytics\Support\Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One thing that happened, on its way to becoming part of a counter.
 *
 * A hit exists for the length of a spool line. Nothing here is ever stored as a
 * row: the drain folds these into aggregates and the line is deleted. Note what
 * the object has no field for - an address, in any form.
 *
 * Nor a user agent, nor a referrer URL. Both used to travel whole and be
 * reduced at the drain, which meant a full user agent and a full referrer -
 * query string, tokens and all - sat in the spool file for as long as the
 * drain took to run. Both are now reduced in the request that saw them:
 * `$device` is four families from {@see \HonestAnalytics\Devices\Device} and
 * `$referrer` is a bare scheme and host. Nothing downstream ever had the rest,
 * so nothing downstream can leak it.
 */
final class Hit {

	public const KIND_VIEW     = 'view';
	public const KIND_EVENT    = 'event';
	public const KIND_OUTBOUND = 'outbound';
	public const KIND_DOWNLOAD = 'download';
	public const KIND_CRAWLER  = 'crawler';

	/** Beyond this a value is a typo or an attack, not a measurement. */
	public const MAX_EVENT_VALUE = 1000000000.0;

	/**
	 * The referrer, always as a bare origin.
	 *
	 * Not promoted, because it is the one field that is normalised rather than
	 * assigned: whatever a caller passes, what this object holds is a scheme
	 * and a host. Doing it here rather than only in the two capture paths is
	 * what makes the guarantee structural - an integration, a theme calling
	 * `honest_analytics_track_event()`, or anything hooked to
	 * `honest_analytics_before_track` cannot put a query string into storage
	 * by not knowing it should not.
	 */
	public readonly string $referrer;

	public function __construct(
		public readonly int $siteId,
		public readonly string $path,
		public readonly string $visitorHash,
		public readonly string $sessionKey,
		public readonly int $timestamp,
		public readonly ?int $postId = null,
		string $referrer = '',
		public readonly string $device = '',
		public readonly int $dwellMs = 0,
		public readonly bool $countView = true,
		public readonly ?string $visitorId = null,
		public readonly ?int $userId = null,
		public readonly ?Campaign $campaign = null,
		public readonly string $countryCode = '',
		public readonly string $region = '',
		public readonly string $kind = self::KIND_VIEW,
		public readonly ?string $eventName = null,
		public readonly ?float $eventValue = null,
		public readonly ?string $target = null,
		public readonly ?int $scrollBucket = null,
		public readonly ?string $searchTerm = null
	) {
		$this->referrer = Url::origin( $referrer );
	}

	/**
	 * Whether this hit is a page being looked at.
	 */
	public function isPageview(): bool {
		return self::KIND_VIEW === $this->kind;
	}

	/**
	 * A copy with some fields replaced.
	 *
	 * @param array<string,mixed> $changes Field overrides.
	 */
	public function with( array $changes ): self {
		return new self(
			$changes['siteId'] ?? $this->siteId,
			$changes['path'] ?? $this->path,
			$changes['visitorHash'] ?? $this->visitorHash,
			$changes['sessionKey'] ?? $this->sessionKey,
			$changes['timestamp'] ?? $this->timestamp,
			array_key_exists( 'postId', $changes ) ? $changes['postId'] : $this->postId,
			$changes['referrer'] ?? $this->referrer,
			$changes['device'] ?? $this->device,
			$changes['dwellMs'] ?? $this->dwellMs,
			array_key_exists( 'countView', $changes ) ? (bool) $changes['countView'] : $this->countView,
			array_key_exists( 'visitorId', $changes ) ? $changes['visitorId'] : $this->visitorId,
			array_key_exists( 'userId', $changes ) ? $changes['userId'] : $this->userId,
			array_key_exists( 'campaign', $changes ) ? $changes['campaign'] : $this->campaign,
			$changes['countryCode'] ?? $this->countryCode,
			$changes['region'] ?? $this->region,
			$changes['kind'] ?? $this->kind,
			array_key_exists( 'eventName', $changes ) ? $changes['eventName'] : $this->eventName,
			array_key_exists( 'eventValue', $changes ) ? $changes['eventValue'] : $this->eventValue,
			array_key_exists( 'target', $changes ) ? $changes['target'] : $this->target,
			array_key_exists( 'scrollBucket', $changes ) ? $changes['scrollBucket'] : $this->scrollBucket,
			array_key_exists( 'searchTerm', $changes ) ? $changes['searchTerm'] : $this->searchTerm
		);
	}

	/**
	 * The spool representation.
	 *
	 * Short keys and no empty values, because this is written once per view on
	 * a busy site and read back a few minutes later. Nothing else reads it.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$data = [
			'si' => $this->siteId,
			'p'  => $this->path,
			'v'  => $this->visitorHash,
			'k'  => $this->sessionKey,
			't'  => $this->timestamp,
			'e'  => $this->postId,
			'r'  => $this->referrer,
			'dv' => $this->device,
			'd'  => $this->dwellMs,
			'nv' => $this->countView ? null : 1,
			'vi' => $this->visitorId,
			'ui' => $this->userId,
			'cm' => $this->campaign?->toArray(),
			'cc' => $this->countryCode,
			'rg' => $this->region,
			'kd' => self::KIND_VIEW === $this->kind ? null : $this->kind,
			'en' => $this->eventName,
			'ev' => $this->eventValue,
			'tg' => $this->target,
			'sb' => $this->scrollBucket,
			'sq' => $this->searchTerm,
		];

		return array_filter(
			$data,
			static fn ( $value ) => null !== $value && '' !== $value && 0 !== $value
		);
	}

	/**
	 * Rebuild a hit from a spool line array.
	 *
	 * @param array<string,mixed> $data Decoded line.
	 */
	public static function fromArray( array $data ): ?self {
		$siteId = isset( $data['si'] ) ? (int) $data['si'] : 0;
		$path   = isset( $data['p'] ) && is_scalar( $data['p'] ) ? (string) $data['p'] : '';
		$kind   = isset( $data['kd'] ) && is_scalar( $data['kd'] ) ? (string) $data['kd'] : self::KIND_VIEW;

		if ( $siteId <= 0 ) {
			return null;
		}

		// A view of no page is a malformed line and goes in the bin. An event
		// with no page is an ordinary fact: a payment provider's callback has
		// no browser behind it and therefore no referrer, and recording the
		// order against the home page instead would be a confident wrong
		// answer. The rollup stores that as pathDimId 0.
		if ( '' === $path && self::KIND_VIEW === $kind ) {
			return null;
		}

		$campaign = isset( $data['cm'] ) && is_array( $data['cm'] ) ? Campaign::fromArray( $data['cm'] ) : null;

		return new self(
			$siteId,
			$path,
			isset( $data['v'] ) && is_scalar( $data['v'] ) ? (string) $data['v'] : '',
			isset( $data['k'] ) && is_scalar( $data['k'] ) ? (string) $data['k'] : '',
			isset( $data['t'] ) ? (int) $data['t'] : time(),
			isset( $data['e'] ) ? (int) $data['e'] : null,
			isset( $data['r'] ) && is_scalar( $data['r'] ) ? (string) $data['r'] : '',
			isset( $data['dv'] ) && is_scalar( $data['dv'] ) ? (string) $data['dv'] : '',
			isset( $data['d'] ) ? (int) $data['d'] : 0,
			! isset( $data['nv'] ),
			isset( $data['vi'] ) && is_scalar( $data['vi'] ) ? (string) $data['vi'] : null,
			isset( $data['ui'] ) ? (int) $data['ui'] : null,
			$campaign,
			isset( $data['cc'] ) && is_scalar( $data['cc'] ) ? (string) $data['cc'] : '',
			isset( $data['rg'] ) && is_scalar( $data['rg'] ) ? (string) $data['rg'] : '',
			$kind,
			isset( $data['en'] ) && is_scalar( $data['en'] ) ? (string) $data['en'] : null,
			isset( $data['ev'] ) ? self::clampEventValue( $data['ev'] ) : null,
			isset( $data['tg'] ) && is_scalar( $data['tg'] ) ? (string) $data['tg'] : null,
			isset( $data['sb'] ) ? (int) $data['sb'] : null,
			isset( $data['sq'] ) && is_scalar( $data['sq'] ) ? (string) $data['sq'] : null
		);
	}

	/**
	 * The spool line.
	 */
	public function encode(): string {
		$json = wp_json_encode( $this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			throw new \RuntimeException( 'Could not encode a hit for the spool.' );
		}

		return $json;
	}

	/**
	 * Read a spool line.
	 *
	 * @param string $line JSON line.
	 */
	public static function decode( string $line ): ?self {
		$data = json_decode( $line, true );

		return is_array( $data ) ? self::fromArray( $data ) : null;
	}

	/**
	 * Bring an event value inside what the column can hold.
	 *
	 * Applied at every entry point, including server-side code: an order total
	 * from a commerce plugin gets no more trust than a number off the beacon,
	 * because both end up in the same decimal column and an overflow there
	 * fails the whole batch.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function clampEventValue( mixed $value ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		if ( ! is_finite( $number ) ) {
			return null;
		}

		return max( -self::MAX_EVENT_VALUE, min( self::MAX_EVENT_VALUE, round( $number, 2 ) ) );
	}
}
