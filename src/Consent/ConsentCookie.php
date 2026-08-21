<?php
/**
 * The one cookie this plugin can set.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Consent;

use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The durable visitor cookie, and the rules it lives by.
 *
 * The anonymous tier - which is to say, the plugin as it arrives and as most
 * sites will run it - sets nothing at all. This cookie exists only for the
 * consented tier, and only ever after somebody has affirmatively agreed. That
 * is why it is written here rather than anywhere near the capture path: there
 * is exactly one place in the codebase that can write to a visitor's device,
 * and it is reached from exactly one route.
 *
 * `HttpOnly` is deliberate even though the value is minted in a browser-facing
 * flow: nothing in the front-end code ever needs to read it, and a value the
 * page cannot read is a value a third-party script cannot read either.
 */
final class ConsentCookie {

	/**
	 * Set or refresh the cookie for a consented visitor.
	 *
	 * @param Settings $settings  Settings.
	 * @param string   $visitorId Durable identifier.
	 */
	public static function set( Settings $settings, string $visitorId ): bool {
		$name = self::name( $settings );

		if ( '' === $name || ! ConsentService::isValidVisitorId( $visitorId ) ) {
			return false;
		}

		$expires = time() + self::duration( $settings );

		$_COOKIE[ $name ] = $visitorId;

		if ( headers_sent() ) {
			return false;
		}

		return setcookie( $name, $visitorId, self::options( $expires ) );
	}

	/**
	 * Remove the cookie.
	 *
	 * Called on a refusal as well as a withdrawal, because "they said no" and
	 * "they changed their mind" have to leave the device in the same state.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function clear( Settings $settings ): bool {
		$name = self::name( $settings );

		if ( '' === $name ) {
			return false;
		}

		unset( $_COOKIE[ $name ] );

		if ( headers_sent() ) {
			return false;
		}

		return setcookie( $name, '', self::options( time() - YEAR_IN_SECONDS ) );
	}

	/**
	 * The identifier this request arrived with, if it is one of ours.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function read( Settings $settings ): ?string {
		$name = self::name( $settings );

		// Read on every request, long before any form is involved. The value is
		// then held to a much stricter standard than sanitisation: it has to
		// be a well-formed visitor id or it is discarded entirely.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = '' !== $name && isset( $_COOKIE[ $name ] )
			? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) )
			: '';

		return ConsentService::isValidVisitorId( $value ) ? $value : null;
	}

	/**
	 * How long the cookie may live.
	 *
	 * Clamped rather than trusted. A configured duration longer than the
	 * ceiling is a mistake or a misunderstanding, and either way the ceiling is
	 * what the Privacy screen promises in writing.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function duration( Settings $settings ): int {
		return max( HOUR_IN_SECONDS, min( Settings::CONSENT_COOKIE_MAX_DURATION, $settings->consentCookieDuration ) );
	}

	/**
	 * The cookie name, sanitised.
	 *
	 * @param Settings $settings Settings.
	 */
	private static function name( Settings $settings ): string {
		$name = trim( $settings->consentCookieName );

		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $name ) ? $name : '';
	}

	/**
	 * The attributes every write shares.
	 *
	 * @param int $expires Expiry timestamp.
	 *
	 * @return array<string,mixed>
	 */
	private static function options( int $expires ): array {
		return [
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];
	}
}
