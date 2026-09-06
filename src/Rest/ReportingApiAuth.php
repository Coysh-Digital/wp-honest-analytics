<?php
/**
 * HMAC authentication for the read-only reporting API.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies the signed, read-only requests an external reporting tool makes to
 * pull a site's aggregated stats.
 *
 * The scheme: an HMAC-SHA256 over method, path, timestamp, nonce and a hash of
 * the (empty) body, with a short timestamp window and a one-shot nonce so a
 * captured request cannot be replayed. Nothing here reads a cookie or a
 * logged-in user - authenticity is the shared secret alone, which is why the
 * secret is the only thing copied between the two ends.
 */
final class ReportingApiAuth {

	/** Where the shared secret lives. */
	public const SECRET_OPTION = 'honest_analytics_reporting_secret';

	/** How far a request timestamp may drift from ours, in seconds. */
	private const TIMESTAMP_TOLERANCE = 300;

	/**
	 * The shared secret: whatever connection code has been saved, or an empty
	 * string when the API has not been connected yet.
	 */
	public static function secret(): string {
		return (string) get_option( self::SECRET_OPTION, '' );
	}

	/**
	 * The REST permission callback: true when the request is correctly signed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		$secret = self::secret();

		if ( '' === $secret ) {
			return new \WP_Error( 'honest_analytics_not_configured', 'Reporting API not configured.', [ 'status' => 403 ] );
		}

		$timestamp = (string) $request->get_header( 'x-cr-timestamp' );
		$nonce     = (string) $request->get_header( 'x-cr-nonce' );
		$signature = (string) $request->get_header( 'x-cr-signature' );

		if ( '' === $timestamp || '' === $nonce || '' === $signature ) {
			return new \WP_Error( 'honest_analytics_unsigned', 'Missing signature.', [ 'status' => 401 ] );
		}

		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			return new \WP_Error( 'honest_analytics_stale', 'Request timestamp out of range.', [ 'status' => 401 ] );
		}

		$nonce_key = 'ha_report_nonce_' . md5( $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return new \WP_Error( 'honest_analytics_replay', 'Nonce already used.', [ 'status' => 401 ] );
		}

		$path     = '/wp-json' . $request->get_route();
		$expected = self::sign( 'GET', $path, $timestamp, $nonce, '', $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error( 'honest_analytics_bad_signature', 'Invalid signature.', [ 'status' => 401 ] );
		}

		set_transient( $nonce_key, 1, self::TIMESTAMP_TOLERANCE * 2 );

		return true;
	}

	/**
	 * Compute a request signature over method, path, timestamp, nonce and a
	 * hash of the body. The consuming client signs requests the same way.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Request path, including the /wp-json prefix.
	 * @param string $timestamp Unix timestamp header.
	 * @param string $nonce     Per-request nonce header.
	 * @param string $body      Raw request body.
	 * @param string $secret    Shared secret.
	 */
	public static function sign( string $method, string $path, string $timestamp, string $nonce, string $body, string $secret ): string {
		$payload = implode(
			"\n",
			[
				strtoupper( $method ),
				$path,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			]
		);

		return hash_hmac( 'sha256', $payload, $secret );
	}
}
