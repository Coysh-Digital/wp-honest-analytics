<?php
/**
 * Which caching layers are in play.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is caching this site, and does it delay JavaScript.
 *
 * Both questions change what the reports will contain, which is why they are
 * asked on the Settings screen rather than left for somebody to discover from a
 * graph that fell off a cliff.
 *
 * A full-page cache means most requests never reach PHP, so server-only
 * tracking would count almost nothing - hybrid is the answer, and the screen
 * says so when it finds a cache. Delayed JavaScript is subtler and worse: the
 * optimiser holds every script back until the visitor interacts with the page,
 * so a visitor who reads an article and leaves without clicking anything is
 * never counted at all. Neither failure announces itself.
 */
final class CacheDetector {

	/**
	 * The caching layers that can be detected.
	 *
	 * @return string[] Human names.
	 */
	public static function detected(): array {
		$found = [];

		foreach ( self::constants() as $constant => $name ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				$found[ $name ] = true;
			}
		}

		foreach ( self::classes() as $class => $name ) {
			if ( class_exists( $class ) ) {
				$found[ $name ] = true;
			}
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$found['SiteGround Optimizer'] = true;
		}

		// The drop-in itself. Named last and only when nothing else explained
		// it, because every plugin above installs one and listing both would
		// read as two caches.
		if ( [] === $found && defined( 'WP_CACHE' ) && constant( 'WP_CACHE' ) ) {
			$found[ __( 'A page cache drop-in', 'honest-analytics' ) ] = true;
		}

		/**
		 * Filters the list of detected caching layers.
		 *
		 * @param string[] $found Human names.
		 */
		return array_values( (array) apply_filters( 'honest_analytics_detected_caches', array_keys( $found ) ) );
	}

	/**
	 * Whether anything is caching pages.
	 */
	public static function hasPageCache(): bool {
		return [] !== self::detected();
	}

	/**
	 * Whether an optimiser is holding scripts back until the visitor interacts.
	 */
	public static function delaysJavaScript(): bool {
		if ( self::rocketDelaysJavaScript() || self::perfmattersDelaysJavaScript() ) {
			return true;
		}

		// LiteSpeed's "delayed" mode for JavaScript is option value 2; 1 is
		// ordinary deferral, which is fine and is what the tracker asks for
		// anyway.
		if ( defined( 'LSCWP_V' ) && 2 === (int) get_option( 'litespeed.conf.optm-js_defer' ) ) {
			return true;
		}

		if ( defined( 'FLYING_SCRIPTS_VERSION' ) ) {
			return true;
		}

		/**
		 * Filters whether an optimiser is delaying JavaScript on this site.
		 *
		 * @param bool $delayed Whether scripts are held back until interaction.
		 */
		return (bool) apply_filters( 'honest_analytics_delays_javascript', false );
	}

	/**
	 * WP Rocket's delay-JavaScript setting.
	 */
	private static function rocketDelaysJavaScript(): bool {
		// WP Rocket is not installed, so there is nothing to ask. Calling the
		// function anyway is a fatal error on the majority of sites.
		if ( ! function_exists( 'get_rocket_option' ) ) {
			return false;
		}

		return (bool) get_rocket_option( 'delay_js' );
	}

	/**
	 * Perfmatters' delay-JavaScript setting.
	 */
	private static function perfmattersDelaysJavaScript(): bool {
		if ( ! defined( 'PERFMATTERS_VERSION' ) ) {
			return false;
		}

		$options = get_option( 'perfmatters_options' );

		return is_array( $options ) && ! empty( $options['assets']['delay_js'] );
	}

	/**
	 * Constants that give a cache away.
	 *
	 * @return array<string,string>
	 */
	private static function constants(): array {
		return [
			'WP_ROCKET_VERSION'          => 'WP Rocket',
			'LSCWP_V'                    => 'LiteSpeed Cache',
			'W3TC'                       => 'W3 Total Cache',
			'WPCACHEHOME'                => 'WP Super Cache',
			'CACHE_ENABLER_VERSION'      => 'Cache Enabler',
			'BREEZE_VERSION'             => 'Breeze',
			'AUTOPTIMIZE_PLUGIN_VERSION' => 'Autoptimize',
			'PERFMATTERS_VERSION'        => 'Perfmatters',
			'CLOUDFLARE_VERSION'         => 'Cloudflare',
			'KINSTA_CACHE_ZONE'          => 'Kinsta',
			'PANTHEON_ENVIRONMENT'       => 'Pantheon',
			'FLYWHEEL_CONFIG_DIR'        => 'Flywheel',
			'NGINX_HELPER_BASENAME'      => 'Nginx Helper',
		];
	}

	/**
	 * Classes that give a cache away.
	 *
	 * @return array<string,string>
	 */
	private static function classes(): array {
		return [
			'WpFastestCache'            => 'WP Fastest Cache',
			'WpeCommon'                 => 'WP Engine',
			'Swift_Performance_Cache'   => 'Swift Performance',
			'Hummingbird\\Core\\Module' => 'Hummingbird',
		];
	}
}
