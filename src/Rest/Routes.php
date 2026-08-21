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

		register_rest_route(
			self::REST_NAMESPACE,
			'/consent',
			[
				'methods'             => 'POST',
				'callback'            => static fn ( \WP_REST_Request $request ) => ConsentController::make()->rest( $request ),
				'permission_callback' => '__return_true',
			]
		);

		self::importRoutes();

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
