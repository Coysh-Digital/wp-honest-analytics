<?php
/**
 * A ceiling on how much one visitor can post.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

use HonestAnalytics\Store\KeyValueStoreInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A fixed window counter, per visitor and per kind of request.
 *
 * This is not a defence against a determined attacker - nothing that counts in
 * the application layer is. It is there so that one runaway script, one badly
 * behaved browser extension, or one page that reloads itself in a loop cannot
 * fill the spool with tens of thousands of lines a minute and drown out
 * everybody else's traffic.
 *
 * The counter is keyed on the rotating visitor hash rather than an address, so
 * the rate limiter holds no more information about anybody than the reports do.
 * When it refuses, the response is the same 204 as a success: a rate limiter
 * that announces itself is a rate limiter somebody can tune against.
 */
final class RateLimit {

	/** One minute. Short enough to forgive a burst, long enough to mean something. */
	public const WINDOW_SECONDS = 60;

	private const PREFIX = 'rl:';

	private KeyValueStoreInterface $store;

	/**
	 * The window this instance counts against.
	 *
	 * Fixed when the limiter is built rather than read per call, so every check
	 * made while serving one request agrees with the others. A request that
	 * happened to straddle a minute boundary would otherwise get a fresh
	 * allowance halfway through answering itself.
	 */
	private int $window;

	/**
	 * @param KeyValueStoreInterface $store Counter storage.
	 * @param int|null               $now   Timestamp, for tests.
	 */
	public function __construct( KeyValueStoreInterface $store, ?int $now = null ) {
		$this->store  = $store;
		$this->window = intdiv( $now ?? time(), self::WINDOW_SECONDS );
	}

	/**
	 * Whether this request takes the visitor past the ceiling.
	 *
	 * Counted before the answer is given, so the request that crosses the line
	 * is the one refused.
	 *
	 * @param string $bucket   What is being limited: 'beacon', 'consent'.
	 * @param string $identity The visitor hash, or another rotating identifier.
	 * @param int    $ceiling  Requests allowed per window. Zero means no limit.
	 */
	public function exceeded( string $bucket, string $identity, int $ceiling ): bool {
		if ( $ceiling <= 0 ) {
			return false;
		}

		return $this->store->increment( $this->key( $bucket, $identity ), self::WINDOW_SECONDS ) > $ceiling;
	}

	/**
	 * How many requests this visitor has made in the current window.
	 *
	 * @param string $bucket   What is being limited.
	 * @param string $identity The visitor hash.
	 */
	public function count( string $bucket, string $identity ): int {
		return (int) $this->store->get( $this->key( $bucket, $identity ) );
	}

	/**
	 * The counter key.
	 *
	 * The window number is part of the key, so a window expires by being
	 * abandoned rather than by anything having to sweep it - and the identity is
	 * hashed so the key length does not depend on what was sent.
	 *
	 * @param string $bucket   What is being limited.
	 * @param string $identity The visitor hash.
	 */
	private function key( string $bucket, string $identity ): string {
		return self::PREFIX . $bucket . ':' . $this->window . ':' . substr( hash( 'sha256', $identity ), 0, 16 );
	}
}
