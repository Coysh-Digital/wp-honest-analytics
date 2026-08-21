<?php
/**
 * Anonymous visitor identity.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Identity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a request into a number that is stable for a day and meaningless after.
 *
 * The address is a parameter here and nowhere else. It is concatenated, hashed,
 * and gone when this method returns - it is never written to a table, a log, a
 * cache key, a queue or the spool file. Truncating to eight bytes is
 * deliberate: collisions are negligible at any realistic daily visitor count,
 * the value is cheap to keep in a sketch, and it is useless as a re-identifier
 * once the salt that produced it has been destroyed.
 */
final class IdentityService {

	/** Bytes of the digest kept. Sixteen hex characters. */
	public const HASH_BYTES = 8;

	private SaltService $salts;

	/**
	 * @param SaltService $salts Salt service.
	 */
	public function __construct( SaltService $salts ) {
		$this->salts = $salts;
	}

	/**
	 * The visitor hash for a request.
	 *
	 * @param string $ip        Client address. Discarded with this frame.
	 * @param string $userAgent User agent.
	 * @param int    $siteId    Site ID.
	 */
	public function visitorHash( string $ip, string $userAgent, int $siteId ): string {
		$digest = hash(
			'sha256',
			$this->salts->currentSalt() . '|' . $ip . '|' . $userAgent . '|' . $siteId,
			true
		);

		return bin2hex( substr( $digest, 0, self::HASH_BYTES ) );
	}

	/**
	 * The session key derived from a visitor hash.
	 *
	 * Derived rather than random, so the same visitor arriving on a second page
	 * lands in the same session without anything being stored on their device.
	 * It rotates with the salt, which is why a visitor active across a rotation
	 * becomes two sessions.
	 *
	 * @param string $visitorHash Visitor hash.
	 * @param int    $siteId      Site ID.
	 */
	public function sessionKey( string $visitorHash, int $siteId ): string {
		return substr( hash( 'sha256', $visitorHash . '|' . $siteId ), 0, 32 );
	}

	/**
	 * Whether a string is a well-formed visitor hash.
	 *
	 * The single statement of the format rule. The spool reader, the rollup
	 * sink and the sketch all defer to it rather than restating it.
	 *
	 * Accepts an int as well as a string, because a hash that happens to be all
	 * digits arrives as one whenever it has been used as a PHP array key. That
	 * is roughly one visitor in forty thousand, which is often enough to matter
	 * and rare enough to only ever surface in production.
	 *
	 * @param int|string $hash Candidate.
	 */
	public static function isValidHash( int|string $hash ): bool {
		$hash = (string) $hash;

		return strlen( $hash ) === self::HASH_BYTES * 2 && ctype_xdigit( $hash );
	}
}
