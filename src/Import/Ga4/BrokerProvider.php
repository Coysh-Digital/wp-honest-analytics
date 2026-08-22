<?php
/**
 * OAuth through a hosted broker.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages thrown here are technical detail for the log and are never printed; Ga4Exception::friendly() is what any screen shows, and escaping a log line would corrupt the only copy of the diagnostic.

/**
 * The path most people should take, once there is a broker to take it through.
 *
 * The site never holds a Google client secret. It sends the person to the
 * broker, the broker does the conversation with Google, and what comes back is
 * a refresh token for this site alone. That keeps one secret in one place
 * instead of a copy of it in every customer's database, and it means a
 * compromised WordPress install leaks one site's read-only analytics access
 * rather than everybody's.
 *
 * There is no broker yet. `isConfigured()` answers false until somebody filters
 * a URL in, and the screen says so rather than pretending to connect.
 *
 * `exchangeCode()`, `refresh()` and `revoke()` send this site's licence key
 * alongside `site`. `authorizationUrl()` does not and cannot: it is a bare
 * browser GET, so a key there would sit in the query string of every proxy
 * access log between here and the broker, the same reason StripeProvider's
 * key never travels there either. The broker treats the same-origin match
 * between `site` and `redirect` as the security boundary for that leg.
 *
 * The key is read straight from {@see SettingsRepository} rather than through
 * `HonestAnalytics\Licensing\LicenceService::key()`. GA4 import ships in Lite,
 * where the whole of `src/Licensing/` does not exist, so this class cannot
 * depend on anything in it - the same reason {@see \HonestAnalytics\Import\GoogleCloudCredentials}
 * lives outside that namespace too. Sent unnormalised: the broker normalises
 * on receipt the same way `StripeProvider`'s server side already does, so a
 * second normalisation here would only be a second implementation of that
 * rule to disagree with the first.
 */
final class BrokerProvider implements Ga4ProviderInterface {

	public function id(): string {
		return 'broker';
	}

	public function label(): string {
		return __( 'Honest Analytics', 'honest-analytics' );
	}

	/**
	 * The broker's base URL, or '' when there is not one.
	 *
	 * Filtered rather than hard-coded, for the same reason the licence provider
	 * is: the arrangement is not settled, and inventing an endpoint now would
	 * mean shipping a URL that has to be right forever.
	 */
	public static function baseUrl(): string {
		/**
		 * Filters the base URL of the Google connection broker.
		 *
		 * @param string $url Base URL, without a trailing slash. Empty disables the hosted flow.
		 */
		$url = (string) apply_filters( 'honest_analytics_ga4_broker_url', '' );

		return untrailingslashit( trim( $url ) );
	}

	public function isConfigured(): bool {
		return '' !== self::baseUrl();
	}

	public function configurationHint(): string {
		return __( 'The one-click Google connection is not available on this site yet.', 'honest-analytics' );
	}

	/**
	 * @param string $state       CSRF value.
	 * @param string $redirectUri Where to come back to.
	 */
	public function authorizationUrl( string $state, string $redirectUri ): string {
		return add_query_arg(
			[
				'state'    => rawurlencode( $state ),
				'redirect' => rawurlencode( $redirectUri ),
				'site'     => rawurlencode( home_url( '/' ) ),
			],
			self::baseUrl() . '/start'
		);
	}

	/**
	 * @param string $code        Exchange code issued by the broker.
	 * @param string $redirectUri The URI used to obtain it.
	 *
	 * @throws Ga4Exception When the broker refuses.
	 */
	public function exchangeCode( string $code, string $redirectUri ): Ga4Tokens {
		$body = $this->post(
			self::baseUrl() . '/exchange',
			[
				'key'      => SettingsRepository::get()->licenceKey,
				'code'     => $code,
				'redirect' => $redirectUri,
				'site'     => home_url( '/' ),
			]
		);

		$tokens = Ga4Tokens::fromResponse( $body );

		if ( '' === $tokens->refreshToken ) {
			throw new Ga4Exception( Ga4Exception::FATAL, 'The broker returned no refresh token.' );
		}

		return $tokens;
	}

	/**
	 * @param Ga4Tokens $tokens What is held now.
	 *
	 * @throws Ga4Exception When the grant is gone.
	 */
	public function refresh( Ga4Tokens $tokens ): Ga4Tokens {
		$body = $this->post(
			self::baseUrl() . '/refresh',
			[
				'key'           => SettingsRepository::get()->licenceKey,
				'refresh_token' => $tokens->refreshToken,
				'site'          => home_url( '/' ),
			]
		);

		return Ga4Tokens::fromResponse( $body, $tokens->refreshToken );
	}

	/**
	 * @param Ga4Tokens $tokens What is held now.
	 */
	public function revoke( Ga4Tokens $tokens ): bool {
		try {
			$this->post(
				self::baseUrl() . '/revoke',
				[
					'key'           => SettingsRepository::get()->licenceKey,
					'refresh_token' => $tokens->refreshToken,
					'site'          => home_url( '/' ),
				]
			);

			return true;
		} catch ( Ga4Exception $e ) {
			// The local connection goes either way. A broker that cannot be
			// reached must not leave somebody unable to disconnect.
			return false;
		}
	}

	/**
	 * A JSON POST to the broker.
	 *
	 * @param string              $url  Endpoint.
	 * @param array<string,mixed> $body Payload.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws Ga4Exception On anything other than a 200 with JSON.
	 */
	private function post( string $url, array $body ): array {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 20,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => (string) wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Ga4Exception( Ga4Exception::TRANSIENT, 'Broker request failed: ' . $response->get_error_message() );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$detail = self::errorDetail( $response );

		if ( 401 === $code || 403 === $code ) {
			throw new Ga4Exception( Ga4Exception::AUTH, 'Broker rejected the grant with ' . $code . $detail . '.' );
		}

		if ( 429 === $code || $code >= 500 ) {
			throw new Ga4Exception(
				429 === $code ? Ga4Exception::RATE_LIMIT : Ga4Exception::TRANSIENT,
				'Broker responded ' . $code . $detail . '.'
			);
		}

		if ( 200 !== $code ) {
			throw new Ga4Exception( Ga4Exception::FATAL, 'Broker responded ' . $code . $detail . '.' );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * The broker's own `error` slug, where the body carries one - for the log
	 * message only.
	 *
	 * Deliberately not turned into a REASON_* constant the way the direct
	 * provider's `invalid_client`/`redirect_uri_mismatch` are: those sentences
	 * tell somebody to open their Google Cloud OAuth client and check it, and a
	 * site connected through the broker has no such client to open. A
	 * broker-side failure is something to diagnose from the log, not something
	 * this site's admin screen can act on by itself.
	 *
	 * @param array<string,mixed>|\WP_Error $response The response from wp_remote_post().
	 */
	private static function errorDetail( $response ): string {
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$error   = is_array( $decoded ) && isset( $decoded['error'] ) ? (string) $decoded['error'] : '';

		return '' !== $error ? ' (' . $error . ')' : '';
	}
}
