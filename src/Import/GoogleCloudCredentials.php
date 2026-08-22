<?php
/**
 * The site's own Google Cloud OAuth client id and secret.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One Google Cloud client, shared by every Google connection this site makes.
 *
 * A Google Cloud OAuth client is not tied to one API - the same client id and
 * secret can request analytics.readonly today and webmasters.readonly
 * tomorrow, as long as both APIs are switched on in the same Cloud project.
 * GA4 import and Search Console import are separate connections with separate
 * tokens and separate scopes, but there is no reason to make somebody paste
 * the same client id and secret into two different boxes, so both providers'
 * `GoogleClientProvider` delegate here instead of keeping their own copy.
 *
 * Kept under the GA4 provider's original option name rather than a new one:
 * this class was extracted out of it, verbatim, and a site that connected GA4
 * before Search Console import existed must not lose its saved credentials
 * because the option they live under changed.
 */
final class GoogleCloudCredentials {

	/** Where the client id and secret live. Not autoloaded. */
	public const OPTION = 'honest_analytics_ga4_client';

	/**
	 * The stored client id and secret.
	 *
	 * @return array{id:string,secret:string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );

		return [
			'id'     => is_array( $stored ) && isset( $stored['id'] ) ? (string) $stored['id'] : '',
			'secret' => is_array( $stored ) && isset( $stored['secret'] ) ? (string) $stored['secret'] : '',
		];
	}

	/**
	 * Save a client id and secret.
	 *
	 * Added with autoload off, deliberately: a secret has no business being
	 * loaded into memory on every request on the site, including the front end.
	 *
	 * @param string $id     Client id.
	 * @param string $secret Client secret.
	 */
	public static function save( string $id, string $secret ): void {
		$id     = trim( $id );
		$secret = trim( $secret );

		if ( '' === $id || '' === $secret ) {
			delete_option( self::OPTION );

			return;
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, [], '', false );
		}

		update_option(
			self::OPTION,
			[
				'id'     => $id,
				'secret' => $secret,
			],
			false
		);
	}

	/**
	 * Whether a client id and secret are saved.
	 */
	public static function isConfigured(): bool {
		$credentials = self::get();

		return '' !== $credentials['id'] && '' !== $credentials['secret'];
	}
}
