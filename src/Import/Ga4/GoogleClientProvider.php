<?php
/**
 * OAuth with the site's own Google Cloud client.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages thrown here are technical detail for the log and are never printed; Ga4Exception::friendly() is what any screen shows, and escaping a log line would corrupt the only copy of the diagnostic.

/**
 * The path for people who would rather not involve anybody else.
 *
 * The site registers its own OAuth client in Google Cloud and talks to Google
 * directly. It costs ten minutes of setup, which is why it is not the default,
 * and it means no third party - including us - ever sees the connection. For a
 * plugin whose entire premise is that your analytics do not leave your server,
 * that is a preference worth supporting properly rather than treating as an
 * escape hatch.
 *
 * The client secret lives in a non-autoloaded option and never leaves the
 * server. It is not exposed by any REST route, not printed on any screen, and
 * not written to the log.
 */
final class GoogleClientProvider implements Ga4ProviderInterface {

	/** Where the client id and secret live. Not autoloaded. */
	public const OPTION = 'honest_analytics_ga4_client';

	private const AUTH_ENDPOINT   = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
	private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

	public function id(): string {
		return 'google';
	}

	public function label(): string {
		return __( 'your own Google Cloud project', 'honest-analytics' );
	}

	public function isConfigured(): bool {
		$credentials = self::credentials();

		return '' !== $credentials['id'] && '' !== $credentials['secret'];
	}

	public function configurationHint(): string {
		return __( 'Add a Google Cloud OAuth client to connect directly to Google from this site.', 'honest-analytics' );
	}

	/**
	 * The stored client id and secret.
	 *
	 * @return array{id:string,secret:string}
	 */
	public static function credentials(): array {
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
	public static function saveCredentials( string $id, string $secret ): void {
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
	 * @param string $state       CSRF value.
	 * @param string $redirectUri Where Google returns to.
	 */
	public function authorizationUrl( string $state, string $redirectUri ): string {
		$credentials = self::credentials();

		return add_query_arg(
			[
				'client_id'              => rawurlencode( $credentials['id'] ),
				'redirect_uri'           => rawurlencode( $redirectUri ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( Client::SCOPE ),
				'state'                  => rawurlencode( $state ),
				// Offline, because the import outlives the browser session that
				// started it. Consent forced, because Google only issues a
				// refresh token the first time somebody approves and a site
				// that reconnects would otherwise get an hour of access.
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'false',
			],
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * @param string $code        Authorisation code.
	 * @param string $redirectUri The URI used to obtain it.
	 *
	 * @throws Ga4Exception When Google refuses.
	 */
	public function exchangeCode( string $code, string $redirectUri ): Ga4Tokens {
		$credentials = self::credentials();

		$body = $this->post(
			[
				'code'          => $code,
				'client_id'     => $credentials['id'],
				'client_secret' => $credentials['secret'],
				'redirect_uri'  => $redirectUri,
				'grant_type'    => 'authorization_code',
			]
		);

		$tokens = Ga4Tokens::fromResponse( $body );

		if ( '' === $tokens->refreshToken ) {
			throw new Ga4Exception(
				Ga4Exception::FATAL,
				'Google returned no refresh token; the client may already have been granted offline access.'
			);
		}

		return $tokens;
	}

	/**
	 * @param Ga4Tokens $tokens What is held now.
	 *
	 * @throws Ga4Exception When the grant is gone.
	 */
	public function refresh( Ga4Tokens $tokens ): Ga4Tokens {
		$credentials = self::credentials();

		$body = $this->post(
			[
				'refresh_token' => $tokens->refreshToken,
				'client_id'     => $credentials['id'],
				'client_secret' => $credentials['secret'],
				'grant_type'    => 'refresh_token',
			]
		);

		return Ga4Tokens::fromResponse( $body, $tokens->refreshToken );
	}

	/**
	 * @param Ga4Tokens $tokens What is held now.
	 */
	public function revoke( Ga4Tokens $tokens ): bool {
		$response = wp_remote_post(
			self::REVOKE_ENDPOINT,
			[
				'timeout' => 15,
				'body'    => [ 'token' => $tokens->refreshToken ],
			]
		);

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * A form POST to Google's token endpoint.
	 *
	 * @param array<string,string> $body Form fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws Ga4Exception On anything other than a 200 with JSON.
	 */
	private function post( array $body ): array {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			[
				'timeout' => 20,
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Ga4Exception( Ga4Exception::TRANSIENT, 'Token request failed: ' . $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];

		if ( 200 === $code ) {
			return $decoded;
		}

		// Google answers a dead or revoked grant with 400 invalid_grant, which
		// looks like a client error and is really "ask them to sign in again".
		$error = isset( $decoded['error'] ) ? (string) $decoded['error'] : '';

		if ( 401 === $code || 'invalid_grant' === $error ) {
			throw new Ga4Exception( Ga4Exception::AUTH, 'Google rejected the grant: ' . ( '' !== $error ? $error : $code ) );
		}

		if ( 429 === $code || $code >= 500 ) {
			throw new Ga4Exception(
				429 === $code ? Ga4Exception::RATE_LIMIT : Ga4Exception::TRANSIENT,
				'Google token endpoint responded ' . $code . '.'
			);
		}

		throw new Ga4Exception( Ga4Exception::FATAL, 'Google token endpoint responded ' . $code . ' (' . $error . ').' );
	}
}
