<?php
/**
 * The empty response every beacon gets.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 204, always, with nothing in it.
 *
 * The beacon endpoints answer identically whether a hit was counted, refused
 * for a privacy signal, deduplicated, rate limited or dropped as a crawler.
 * That is not laziness - a response that varied would let anybody with a
 * browser probe how the site is configured, whether a given visitor had already
 * been seen, or whether a path is excluded, one request at a time. The reader
 * of the reports learns what happened; the sender of the beacon learns nothing.
 *
 * `no-store` matters as much as the status. Without it a CDN can and will cache
 * a beacon response, and a cached 204 is a counter that stops counting.
 */
final class NoContent {

	public const STATUS = 204;

	/**
	 * Attach the serialiser bypass.
	 */
	public static function register(): void {
		add_filter( 'rest_pre_serve_request', [ self::class, 'serve' ], 10, 3 );
	}

	/**
	 * The response object a route callback returns.
	 */
	public static function response(): \WP_REST_Response {
		return new \WP_REST_Response( null, self::STATUS );
	}

	/**
	 * Send an empty body rather than letting the REST server serialise one.
	 *
	 * Left to itself the REST server writes `null` into the body as the four
	 * bytes "null" and declares them JSON, which is a body on a status that is
	 * defined as having none.
	 *
	 * @param bool  $served  Whether the request has already been served.
	 * @param mixed $result  The response, usually a WP_HTTP_Response.
	 * @param mixed $request The request, usually a WP_REST_Request.
	 */
	public static function serve( bool $served, mixed $result, mixed $request ): bool {
		if ( $served ) {
			return $served;
		}

		if ( ! $request instanceof \WP_REST_Request || ! $result instanceof \WP_HTTP_Response ) {
			return $served;
		}

		if ( ! str_starts_with( (string) $request->get_route(), '/' . Routes::REST_NAMESPACE . '/' ) ) {
			return $served;
		}

		if ( self::STATUS !== $result->get_status() ) {
			return $served;
		}

		self::sendHeaders();

		return true;
	}

	/**
	 * Send the headers an empty, uncacheable response needs.
	 */
	public static function sendHeaders(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Content-Length: 0' );
		header( 'X-Content-Type-Options: nosniff' );
		header_remove( 'Content-Type' );
	}

	/**
	 * Send the whole response, for the endpoint that does not go through REST.
	 */
	public static function send(): void {
		if ( ! headers_sent() ) {
			status_header( self::STATUS );
		}

		self::sendHeaders();
	}
}
