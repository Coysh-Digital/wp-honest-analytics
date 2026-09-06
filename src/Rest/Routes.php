<?php
/**
 * REST route registration.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A handful of routes, on this site's own domain.
 *
 * First-party by construction: the tracker posts to a path on the site it is
 * measuring, so there is no third-party request to block, no external host to
 * trust, and nothing for a content blocker to recognise. It also means the
 * beacon carries the site's own cookies - which is why nothing here reads them
 * except the consented tier, and why the response is a bare 204.
 */
final class Routes {

	/** Not `NAMESPACE`: that is a keyword, and reading it back as one is a trap. */
	public const REST_NAMESPACE = 'honest-analytics/v1';

	/**
	 * Attach the registration hook.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	/**
	 * Register the routes.
	 */
	public static function routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/collect',
			[
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $request ) => CollectController::make()->rest( $request ),
				// Public by design: the visitors being counted are not logged
				// in. Everything the endpoint will accept is validated inside
				// it, and it answers 204 whatever happens.
				'permission_callback' => '__return_true',
			]
		);

		self::consentRoute();

		self::importRoutes();
		self::reportingApiRoutes();

		register_rest_route(
			self::REST_NAMESPACE,
			'/realtime',
			[
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ) => RealtimeController::make()->rest( $request ),
				'permission_callback' => [ RealtimeController::class, 'permission' ],
				'args'                => [
					'limit' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * The route a visitor's consent decision posts to.
	 *
	 * Registered only when the controller is in this build. The consented tier
	 * is not in every package, and a route whose callback names a missing
	 * class is not a 404 - it is an uncaught Error inside the REST dispatcher,
	 * which is a 500 with a stack trace in the log.
	 */
	private static function consentRoute(): void {
		if ( ! class_exists( ConsentController::class ) ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/consent',
			[
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $request ) => ConsentController::make()->rest( $request ),
				// Public for the same reason as /collect: the visitor recording
				// a consent decision is not logged in, so there is nobody to
				// authenticate. The body is validated inside the controller and
				// the response is a bare 204.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * The read-only reporting API: a verify handshake and a period report, both
	 * behind an HMAC signature check (see ReportingApiAuth). Lets an external
	 * reporting tool pull this site's aggregated stats.
	 */
	private static function reportingApiRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/verify',
			[
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ) => ReportingApiController::make()->verify(),
				'permission_callback' => [ ReportingApiAuth::class, 'authenticate' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/report',
			[
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ) => ReportingApiController::make()->report( $request ),
				'permission_callback' => [ ReportingApiAuth::class, 'authenticate' ],
				'args'                => [
					'from' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'to'   => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * The migration screen's routes.
	 *
	 * Registered separately, and only when the import system is in this build,
	 * so that a package without it has four routes rather than a fatal.
	 */
	private static function importRoutes(): void {
		if ( ! class_exists( \HonestAnalytics\Import\Rest\ImportController::class ) ) {
			return;
		}

		$controller = \HonestAnalytics\Import\Rest\ImportController::class;

		register_rest_route(
			self::REST_NAMESPACE,
			'/import/start',
			[
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $request ) => $controller::make()->start( $request ),
				'permission_callback' => [ $controller, 'permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/import/(?P<id>\d+)/tick',
			[
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $request ) => $controller::make()->tick( $request ),
				'permission_callback' => [ $controller, 'permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/import/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ) => $controller::make()->progress( $request ),
				'permission_callback' => [ $controller, 'permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/import/(?P<id>\d+)/cancel',
			[
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $request ) => $controller::make()->cancel( $request ),
				'permission_callback' => [ $controller, 'permission' ],
			]
		);
	}
}
