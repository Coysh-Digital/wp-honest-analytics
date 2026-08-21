<?php
/**
 * Turning a URL an integration was handed into a page this site knows.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by the integrations that know which page their event happened on.
 *
 * The rule throughout is that null means "I do not know", and null is an
 * acceptable answer. Attributing a conversion to a page it did not happen on is
 * worse than not attributing it at all, because the wrong number is believable
 * and the missing one is obvious.
 */
trait ResolvesPage {

	/**
	 * Turn a page URL into a path this site recognises.
	 *
	 * Returns null when the URL is missing, unparseable or somewhere else
	 * entirely - a submission posted from another domain has no page here to
	 * attribute it to.
	 *
	 * @param string|null $url Absolute or relative URL.
	 */
	final protected function pathFrom( ?string $url ): ?string {
		$url = null !== $url ? trim( $url ) : '';

		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			return null;
		}

		if ( isset( $parts['host'] ) && '' !== $parts['host'] ) {
			$home = wp_parse_url( home_url( '/' ) );
			$here = is_array( $home ) && isset( $home['host'] ) ? (string) $home['host'] : '';

			if ( '' === $here || strtolower( (string) $parts['host'] ) !== strtolower( $here ) ) {
				return null;
			}
		}

		$path = (string) $parts['path'];

		// parse_url() will happily call any old string a path, so "not a url"
		// arrives here looking like one. A path on this site starts at the
		// root; anything else cannot be resolved without guessing a base, and
		// guessing is what this method exists not to do.
		if ( ! str_starts_with( $path, '/' ) ) {
			return null;
		}

		return isset( $parts['query'] ) && '' !== $parts['query']
			? $path . '?' . $parts['query']
			: $path;
	}

	/**
	 * The post the page is, when that can be established cheaply.
	 *
	 * One query, on an event that happens a handful of times a day, in exchange
	 * for the conversion appearing in that post's own analytics panel. Returns
	 * null rather than 0 so the rollup's fill-if-null leaves the column alone
	 * for a page that is not a post.
	 *
	 * @param string|null $url Absolute or relative URL.
	 */
	final protected function postIdFrom( ?string $url ): ?int {
		if ( null === $url || '' === trim( $url ) || ! function_exists( 'url_to_postid' ) ) {
			return null;
		}

		$postId = (int) url_to_postid( $url );

		return $postId > 0 ? $postId : null;
	}
}
