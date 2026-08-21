<?php
/**
 * Keeping the public endpoints reachable.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Undoes a blanket "require authentication for the REST API" - for two routes
 * and no others.
 *
 * A great many security plugins, and a fair number of hosts, add a
 * `rest_authentication_errors` handler that refuses every REST request from a
 * logged-out visitor. That is a reasonable default for a site with no public
 * API. It is also, silently, a setting that stops analytics counting anybody
 * who is not logged in - which is everybody - and the symptom is a dashboard
 * that reads zero with no error anywhere.
 *
 * So this runs last and clears the error, but only for `collect` and `consent`,
 * only for POST, and never for the real-time endpoint, which reads data and
 * stays behind a capability check. A site that would rather not have this can
 * turn it off with one filter.
 */
final class RestUnlock {

	/**
	 * The routes that have to answer an anonymous visitor.
	 *
	 * @var string[]
	 */
	private const PUBLIC_ROUTES = [ 'collect', 'consent' ];

	/**
	 * Attach the filter, last.
	 */
	public static function register(): void {
		add_filter( 'rest_authentication_errors', [ self::class, 'allow' ], PHP_INT_MAX );
	}

	/**
	 * Clear an authentication error for our own public routes.
	 *
	 * @param mixed $result True, false, null or a WP_Error from an earlier filter.
	 */
	public static function allow( mixed $result ): mixed {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! self::isPublicRoute() ) {
			return $result;
		}

		/**
		 * Filters whether the beacon endpoints are exempted from a site-wide
		 * REST authentication requirement.
		 *
		 * Returning false leaves the site's own rule in place. Analytics will
		 * then only count logged-in visitors, which is almost certainly not
		 * what anybody wants, but it is the site's decision to make.
		 *
		 * @param bool $unlock Whether to allow anonymous access.
		 */
		if ( ! apply_filters( 'honest_analytics_unlock_rest', true ) ) {
			return $result;
		}

		// Null rather than true: true asserts that the request *is*
		// authenticated, which it is not. Null means "no opinion", and lets the
		// route's own permission callback decide.
		return null;
	}

	/**
	 * Whether the request being authenticated is one of ours.
	 */
	private static function isPublicRoute(): bool {
		$route = self::currentRoute();

		if ( '' === $route ) {
			return false;
		}

		foreach ( self::PUBLIC_ROUTES as $name ) {
			if ( '/' . Routes::REST_NAMESPACE . '/' . $name === $route ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The route being requested.
	 *
	 * Read from the query var WordPress has already resolved where there is
	 * one, and from the URL where the request came in through the pretty
	 * permalink form before `parse_request` has run.
	 */
	private static function currentRoute(): string {
		global $wp;

		if ( isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ) {
			return '/' . trim( $wp->query_vars['rest_route'], '/' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the route being requested, on a public endpoint, before any decision is taken.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return '/' . trim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $uri ) {
			return '';
		}

		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$prefix = '/' . trim( (string) rest_get_url_prefix(), '/' ) . '/';
		$at     = strpos( $path, $prefix );

		if ( false === $at ) {
			return '';
		}

		return '/' . trim( substr( $path, $at + strlen( $prefix ) ), '/' );
	}
}
