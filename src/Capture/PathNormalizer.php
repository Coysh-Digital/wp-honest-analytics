<?php
/**
 * Path normalisation.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Channels\Campaign;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One page, one row.
 *
 * The same page reached through a newsletter, an ad and a bare link is one page
 * in the report and three URLs in the wild. This decides which parts of a URL
 * are the page's identity and which are noise, and it has to make the same
 * decision for the server-rendered path and for the one the browser reports, or
 * hybrid mode produces two rows for every page.
 */
final class PathNormalizer {

	/**
	 * Parameters that never identify a page.
	 *
	 * Analytics and ad tooling, WordPress preview plumbing, and `replytocom` -
	 * which quietly creates one URL per comment on every post that has any.
	 *
	 * @var string[]
	 */
	public const NOISE_PARAMS = [
		'gad_source',
		'gad_campaignid',
		'gclsrc',
		'srsltid',
		'_gl',
		'_ga',
		'_gac',
		'_gid',
		'_hsenc',
		'_hsmi',
		'mkt_tok',
		'vero_id',
		'vero_conv',
		'yclid',
		'replytocom',
		'preview',
		'preview_id',
		'preview_nonce',
		'_thumbnail_id',
		'customize_changeset_uuid',
		'customize_theme',
		'customize_messenger_channel',
		'customize_autosaved',
		'elementor-preview',
		'et_fb',
		'fl_builder',
		'vc_editable',
		'doing_wp_cron',
		'nocache',
		'unapproved',
		'moderation-hash',
	];

	private Settings $settings;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The stored path for a request.
	 *
	 * @param string $path        Path, with or without a leading slash.
	 * @param string $queryString Raw query string.
	 */
	public function normalize( string $path, string $queryString = '' ): string {
		$path = $this->tidyPath( $path );

		if ( '' === trim( $queryString ) || $this->settings->stripQueryString ) {
			return $path;
		}

		parse_str( $queryString, $params );

		if ( ! is_array( $params ) || [] === $params ) {
			return $path;
		}

		$clean = [];

		foreach ( $params as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			// An HTML-entity-encoded ampersand survives parse_str as a key
			// prefixed with "amp;", and a doubly-encoded link brings two.
			$name = preg_replace( '/^(?:amp;)+/', '', $key );

			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$clean[ $name ] = $value;
		}

		foreach ( $this->droppedParams() as $param ) {
			unset( $clean[ $param ] );
		}

		if ( [] === $clean ) {
			return $path;
		}

		// Sorted, so ?a=1&b=2 and ?b=2&a=1 are one page rather than two rows
		// nobody would think to add together.
		ksort( $clean );

		return $path . '?' . http_build_query( $clean );
	}

	/**
	 * Turn a browser's `location.pathname` into what the server would have seen.
	 *
	 * Never run on the server's own path: decoding and base-path stripping are
	 * not idempotent, and doing them twice mangles a path containing an encoded
	 * slash.
	 *
	 * @param string $pathname Raw pathname from the browser.
	 */
	public function fromBeaconPath( string $pathname ): string {
		$path = rawurldecode( $pathname );
		$path = preg_replace( '#/{2,}#', '/', $path );
		$path = is_string( $path ) ? trim( $path, '/' ) : '';

		$base = Url::basePath();

		if ( '' !== $base && ( $path === $base || str_starts_with( $path . '/', $base . '/' ) ) ) {
			$path = ltrim( substr( $path, strlen( $base ) ), '/' );
		}

		return $this->tidyPath( $path );
	}

	/**
	 * Split a stored path back into its path and query halves.
	 *
	 * @param string $path Path, possibly with a query string.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function split( string $path ): array {
		$position = strpos( $path, '?' );

		if ( false === $position ) {
			return [ $path, '' ];
		}

		return [ substr( $path, 0, $position ), substr( $path, $position + 1 ) ];
	}

	/**
	 * Whether a path matches one of the exclusion patterns.
	 *
	 * @param string $path Path.
	 */
	public function isExcluded( string $path ): bool {
		foreach ( $this->settings->excludePaths as $pattern ) {
			if ( fnmatch( $pattern, $path ) ) {
				return true;
			}
		}

		/**
		 * Filters whether a path is excluded from tracking entirely.
		 *
		 * @param bool   $excluded Whether to exclude.
		 * @param string $path     Normalised path.
		 */
		return (bool) apply_filters( 'honest_analytics_exclude_path', false, $path );
	}

	/**
	 * Leading slash, no trailing slash, no repeated slashes.
	 *
	 * A permalink structure with a trailing slash and one without would
	 * otherwise be two rows for the same page, and which one a visitor gets
	 * depends on whether they arrived by a canonical redirect.
	 *
	 * @param string $path Raw path.
	 */
	private function tidyPath( string $path ): string {
		$path = preg_replace( '#/{2,}#', '/', $path );
		$path = is_string( $path ) ? $path : '/';
		$path = '/' . ltrim( $path, '/' );

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Every parameter removed from a stored path.
	 *
	 * Campaign tags go because they are read first and recorded properly in the
	 * Campaigns report; keeping them here as well would fragment one page into
	 * a row per newsletter.
	 *
	 * @return string[]
	 */
	private function droppedParams(): array {
		$params = array_merge(
			$this->settings->excludeQueryParams,
			Campaign::PARAMS,
			self::NOISE_PARAMS
		);

		/**
		 * Filters the query parameters stripped from stored page paths.
		 *
		 * @param string[] $params Parameter names.
		 */
		return array_values( array_unique( (array) apply_filters( 'honest_analytics_stripped_params', $params ) ) );
	}
}
