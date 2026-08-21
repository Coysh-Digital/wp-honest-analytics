<?php
/**
 * Turning another plugin's idea of a URL into this one's.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Sources;

use HonestAnalytics\Capture\PathNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One path, normalised the way native data is normalised.
 *
 * The point is that `/about`, `/about/`, `https://example.com/about`,
 * `http://www.example.com/about?utm_source=news` and `%2Fabout` are one page in
 * the reports rather than five rows nobody would think to add together - and
 * that they are the *same* one page the plugin's own tracker would have
 * recorded, because a migration that files its history under slightly different
 * paths has preserved nothing.
 *
 * The other half matters just as much: two genuinely different pages must not
 * be merged. `?s=hats` and `?s=coats` are different pages; `?utm_source=news`
 * and `?utm_source=email` are the same one. Which is which is
 * `PathNormalizer`'s business, and this class simply makes sure it is asked.
 */
final class SourceUrl {

	private PathNormalizer $paths;

	/**
	 * Hosts that count as this site.
	 *
	 * @var string[]
	 */
	private array $ownHosts;

	/**
	 * @param PathNormalizer $paths Native path normalisation.
	 */
	public function __construct( PathNormalizer $paths ) {
		$this->paths    = $paths;
		$this->ownHosts = self::ownHosts();
	}

	/**
	 * Normalise whatever a source recorded as a page.
	 *
	 * Returns an empty string for anything that is not a page on this site, so
	 * a caller can skip it: an off-site URL in a page table is a bug in the
	 * source, not a page of ours.
	 *
	 * @param string $raw Whatever the source stored - a path, or a whole URL.
	 */
	public function normalise( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// A stored URL may be encoded once, or - from a source that stored what
		// arrived in a query string - twice. Decoding is done here rather than
		// in PathNormalizer because doing it twice to a path containing an
		// encoded slash mangles it, and PathNormalizer is also used on paths
		// the server has already decoded.
		if ( str_contains( $raw, '%' ) ) {
			$decoded = rawurldecode( $raw );

			if ( '' !== $decoded ) {
				$raw = $decoded;
			}
		}

		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) || str_starts_with( $raw, '//' ) ) {
			$raw = $this->stripHost( $raw );

			if ( '' === $raw ) {
				return '';
			}
		}

		$fragment = strpos( $raw, '#' );

		if ( false !== $fragment ) {
			$raw = substr( $raw, 0, $fragment );
		}

		[ $path, $query ] = PathNormalizer::split( $raw );

		// Collapse repeated slashes before normalising, because a source that
		// concatenated a base and a path has plenty of `//about`.
		$path = preg_replace( '#/{2,}#', '/', $path );
		$path = is_string( $path ) ? $path : '';

		return $this->paths->normalize( $path, $query );
	}

	/**
	 * Remove the scheme and host from an absolute URL.
	 *
	 * Anything on another host is not a page of this site and comes back empty.
	 * Scheme is ignored entirely: a site that moved from HTTP to HTTPS has
	 * history under both, and it is the same history.
	 *
	 * @param string $url Absolute URL.
	 */
	private function stripHost( string $url ): string {
		$parts = wp_parse_url( str_starts_with( $url, '//' ) ? 'https:' . $url : $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( '' !== $host && [] !== $this->ownHosts && ! in_array( self::withoutWww( $host ), $this->ownHosts, true ) ) {
			return '';
		}

		$path  = (string) ( $parts['path'] ?? '/' );
		$query = (string) ( $parts['query'] ?? '' );

		return '' !== $query ? $path . '?' . $query : $path;
	}

	/**
	 * The hosts this site answers to.
	 *
	 * Filterable, because a site that changed domain has years of history under
	 * the old one and there is no way for the plugin to know what it was.
	 *
	 * @return string[]
	 */
	private static function ownHosts(): array {
		$hosts = [];

		foreach ( [ home_url(), site_url() ] as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );

			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = self::withoutWww( strtolower( $host ) );
			}
		}

		/**
		 * Filters the hostnames an import treats as this site.
		 *
		 * Add a previous domain here to import history recorded under it.
		 *
		 * @param string[] $hosts Lower-case hostnames, without `www.`.
		 */
		$filtered = apply_filters( 'honest_analytics_import_own_hosts', array_values( array_unique( $hosts ) ) );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $hosts;
	}

	/**
	 * A hostname without its `www.`.
	 *
	 * @param string $host Hostname.
	 */
	private static function withoutWww( string $host ): string {
		return preg_replace( '/^www\./', '', $host ) ?? $host;
	}
}
