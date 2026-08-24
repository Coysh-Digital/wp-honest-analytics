<?php
/**
 * Encryption at rest for the few values that warrant it.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one thing this plugin holds that is worth stealing.
 *
 * Almost nothing here needs encrypting, which is the whole point of the
 * product: a rollup row is a number, and a visitor hash stops meaning anything
 * the moment the salt rotates. The Google refresh tokens are the exception.
 * One of those is read access to somebody's GA4 property or Search Console
 * until it is revoked, and it sat in `wp_options` in plain text - reachable
 * from any read-only database exposure, any options dump from an unrelated
 * plugin, any backup left in the webroot.
 *
 * So it is encrypted with a key derived from the site's own salts. This does
 * not defeat an attacker who already has the filesystem: `wp-config.php` holds
 * the salts, and anybody reading that has won already. It defeats every
 * attacker who only reached the database, which is most of them.
 *
 * Two consequences worth knowing before changing anything here:
 *
 * - **Rotating the site's salts makes stored tokens unreadable.** That is a
 *   disconnected Google connection and a reconnect, not a fatal: a value that
 *   will not decrypt is treated as absent. `docs/importing.md` says so.
 * - **A value written before this existed is still plain.** It is read as-is
 *   and written back encrypted the next time it is saved, so an upgrade needs
 *   no migration and no reconnect.
 */
final class Secret {

	/**
	 * What marks a value as having been through this class.
	 *
	 * Versioned, so a future change of algorithm can be told from this one
	 * rather than guessed at by length.
	 */
	private const PREFIX = 'ha1:';

	/**
	 * Encrypt a value for storage.
	 *
	 * Returns the value unchanged when there is nothing to encrypt, and when
	 * libsodium is unavailable - which should not happen on PHP 8.1, and does
	 * on the occasional host that has disabled the extension. Storing the
	 * token plainly is worse than storing it encrypted and better than losing
	 * somebody's connection over it, and {@see self::isAvailable()} lets the
	 * Privacy screen say which is happening.
	 *
	 * @param string $plain The value.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::isAvailable() ) {
			return $plain;
		}

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
		} catch ( \Throwable $e ) {
			Log::warning( 'A value could not be encrypted and has been stored as it is: ' . $e->getMessage() );

			return $plain;
		}
	}

	/**
	 * Read a stored value back.
	 *
	 * An empty string for anything that will not decrypt, which is what a
	 * caller should treat as "there is no connection here" rather than as an
	 * error to report: the ordinary cause is a site whose salts have been
	 * rotated, and the answer to that is to connect again.
	 *
	 * @param string $stored The stored value.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! str_starts_with( $stored, self::PREFIX ) ) {
			// Written before this class existed. Returned as it is, and
			// encrypted the next time it is saved.
			return $stored;
		}

		if ( ! self::isAvailable() ) {
			Log::warning( 'A stored value is encrypted but libsodium is not available to read it.' );

			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( false === $plain ) {
			Log::warning( 'A stored value could not be decrypted; the site salts may have been rotated.' );

			return '';
		}

		return $plain;
	}

	/**
	 * Whether values can be encrypted at all on this host.
	 */
	public static function isAvailable(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_generichash' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Whether a stored value is one of ours and encrypted.
	 *
	 * For the Privacy screen, which says plainly whether the Google tokens on
	 * this site are protected or are sitting in the clear.
	 *
	 * @param string $stored The stored value.
	 */
	public static function isEncrypted( string $stored ): bool {
		return str_starts_with( $stored, self::PREFIX );
	}

	/**
	 * The key, derived from the site's own salts.
	 *
	 * `secure_auth` rather than `auth`: the spool filename already uses `auth`
	 * (see Paths::secretSuffix), and two unrelated uses of one salt is how a
	 * value that was only ever meant to be unguessable ends up being the thing
	 * that guards a credential.
	 */
	private static function key(): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : 'honest-analytics';

		return sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
