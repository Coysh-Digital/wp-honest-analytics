<?php
/**
 * URL helpers.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL helpers shared by the injector, the collect endpoint and the reports.
 */
final class Url {

	/**
	 * The host of a URL, lower-cased and with any leading dot removed.
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): ?string {
		if ( '' === trim( $url ) ) {
			return null;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}

		return ltrim( strtolower( $host ), '.' );
	}

	/**
	 * Every host this installation answers to.
	 *
	 * Used to decide whether a referrer is an external traffic source or just
	 * the previous page on this site.
	 *
	 * @return string[]
	 */
	public static function localHosts(): array {
		$hosts = [];

		foreach ( [ home_url(), site_url() ] as $url ) {
			$host = self::host( (string) $url );

			if ( null !== $host ) {
				$hosts[ $host ] = true;
			}
		}

		/**
		 * Filters the hosts treated as this site rather than as a referrer.
		 *
		 * @param string[] $hosts Lower-case host names.
		 */
		return array_values( array_unique( (array) apply_filters( 'honest_analytics_local_hosts', array_keys( $hosts ) ) ) );
	}

	/**
	 * Whether a referrer points back at this site.
	 *
	 * @param string $referrer Referrer URL.
	 */
	public static function isInternal( string $referrer ): bool {
		$host = self::host( $referrer );

		if ( null === $host ) {
			return true;
		}

		return in_array( $host, self::localHosts(), true );
	}

	/**
	 * The site's base path, without leading or trailing slashes.
	 *
	 * A subdirectory install ("/blog") means the browser's location.pathname
	 * carries a prefix the server's own path info does not.
	 */
	public static function basePath(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return '';
		}

		return trim( $path, '/' );
	}

	/**
	 * A root-relative version of a URL.
	 *
	 * The collect endpoint is baked into cached HTML, so it is emitted
	 * root-relative: it then survives a staging clone, a protocol change behind
	 * a proxy, and domain mapping without pointing a visitor's browser at
	 * another site.
	 *
	 * @param string $url URL.
	 */
	public static function relative( string $url ): string {
		$relative = preg_replace( '#^https?://[^/]+#i', '', $url );

		return is_string( $relative ) && '' !== $relative ? $relative : $url;
	}
}
