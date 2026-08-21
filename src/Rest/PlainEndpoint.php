<?php
/**
 * The beacon endpoint that does not go through the REST API.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

use HonestAnalytics\Plugin;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An alternative to `/wp-json/`, for the sites where that is not available.
 *
 * It is available more often than it should be believed: hosts disable the REST
 * API wholesale, security plugins return 403 for anonymous REST requests, and
 * more than one CDN configuration treats `/wp-json/` as an attack surface to be
 * blocked outright. On those sites the reports read zero and nothing explains
 * why.
 *
 * So there is a second door: `?honest-analytics=collect` on the site's own home
 * URL, handled at `parse_request` before WordPress has decided what the request
 * was. It runs the same controllers, applies the same rules, and answers the
 * same 204. It is not the default, because the REST route is the conventional
 * place for this and conventions are worth something.
 */
final class PlainEndpoint {

	public const QUERY_VAR = 'honest-analytics';

	/**
	 * The routes it answers.
	 *
	 * @var string[]
	 */
	private const ROUTES = [ 'collect', 'consent' ];

	/**
	 * Whether this endpoint is the configured one.
	 */
	public static function isEnabled(): bool {
		return Settings::ENDPOINT_PLAIN === Plugin::instance()->settings()->collectEndpoint;
	}

	/**
	 * Attach the handler.
	 *
	 * At priority zero on `parse_request`: before the query is parsed, before a
	 * redirect plugin has an opinion, and long before a template is chosen.
	 */
	public static function register(): void {
		add_action( 'parse_request', [ self::class, 'maybeHandle' ], 0 );
	}

	/**
	 * Handle the request, if it is one of ours.
	 */
	public static function maybeHandle(): void {
		$route = self::route();

		if ( null === $route ) {
			return;
		}

		$server = new Server();

		if ( 'POST' !== $server->method() ) {
			// A GET here is somebody following the URL out of curiosity, or a
			// crawler that found it in a script tag. Neither is a hit.
			NoContent::send();
			exit;
		}

		$params  = self::postedParams();
		$headers = $server->headers();

		try {
			if ( 'consent' === $route ) {
				ConsentController::make()->run( $params, $headers );
			} else {
				CollectController::make()->run( $params, $headers );
			}
		} catch ( \Throwable $e ) {
			Log::warning( 'The plain endpoint could not handle a request: ' . $e->getMessage() );
		}

		NoContent::send();

		// `exit` still runs the shutdown actions, which is where the automatic
		// drain lives - so a fully cached site whose only PHP requests are
		// beacons still drains itself.
		exit;
	}

	/**
	 * The route being asked for, if any.
	 */
	private static function route(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! is_string( $_GET[ self::QUERY_VAR ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

		return in_array( $route, self::ROUTES, true ) ? $route : null;
	}

	/**
	 * The posted fields.
	 *
	 * @return array<string,string>
	 */
	private static function postedParams(): array {
		$out = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( (array) $_POST as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = (string) wp_unslash( (string) $value );
			}
		}

		if ( [] !== $out ) {
			return $out;
		}

		// A body PHP did not parse, because the content type was rewritten
		// somewhere between the browser and here.
		$body = file_get_contents( 'php://input' );

		if ( ! is_string( $body ) || '' === $body ) {
			return [];
		}

		parse_str( $body, $parsed );

		if ( ! is_array( $parsed ) ) {
			return [];
		}

		foreach ( $parsed as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}

		return $out;
	}
}
