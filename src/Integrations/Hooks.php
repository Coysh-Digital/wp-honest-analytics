<?php
/**
 * The public API, and the plugins that use it.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One action a theme can call, and the integrations that call it.
 *
 * Form and commerce events cannot be captured as pageviews. A submission is
 * a POST followed by a redirect: there is no page in it to count, and by the
 * time the visitor sees a thank-you page the thing worth recording has
 * already happened. So they are recorded server-side, by name.
 *
 * What crosses this boundary is a name, sometimes a number, and nothing
 * else. The integrations have a submitted form or a paid order in hand - full
 * of names, addresses and card details - and pass along an identifier and a
 * total. That restraint is the integration.
 *
 * The action itself is not gated: a theme calling it on the free edition gets
 * `false` back from `Plugin::trackEvent()` rather than a fatal, which is the
 * behaviour a theme author can code against. The third-party integrations are
 * gated, in `Registry::register()`, because attaching five sets of hooks that
 * can never record anything is waste.
 */
final class Hooks {

	/**
	 * Attach the API and the integrations.
	 */
	public static function register(): void {
		// Wrapped rather than attached directly: trackEvent() returns whether
		// it recorded anything, and an action callback must not return a value.
		add_action(
			'honest_analytics_track_event',
			static function ( string $name, ?float $value = null, ?string $path = null, ?int $postId = null ): void {
				self::trackEvent( $name, $value, $path, $postId );
			},
			10,
			4
		);

		Registry::register();
	}

	/**
	 * Record an event.
	 *
	 * The action a theme fires, and the one place it lands.
	 *
	 *     do_action( 'honest_analytics_track_event', 'brochure-downloaded' );
	 *     do_action( 'honest_analytics_track_event', 'quote-requested', 250.00 );
	 *
	 * @param string      $name   Event name.
	 * @param float|null  $value  Optional value.
	 * @param string|null $path   Path the event happened on.
	 * @param int|null    $postId Post it relates to.
	 */
	public static function trackEvent( string $name, ?float $value = null, ?string $path = null, ?int $postId = null ): bool {
		return Plugin::instance()->trackEvent( $name, $value, $path, $postId );
	}

	/**
	 * Turn a stored event name into something a person can read.
	 *
	 * A convenience over `Registry::label()`, for the report screens.
	 *
	 * @param string $eventName The stored event name.
	 */
	public static function label( string $eventName ): string {
		return Registry::label( $eventName );
	}
}
