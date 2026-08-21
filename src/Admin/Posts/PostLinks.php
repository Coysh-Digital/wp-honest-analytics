<?php
/**
 * Links from reports back to the editor.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Posts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edit links for the posts a report row refers to.
 *
 * Capability-checked per post, so a contributor looking at the Pages report does
 * not get an edit link to somebody else's draft - and never gets analytics for
 * content they could not otherwise see.
 */
final class PostLinks {

	/**
	 * Edit URLs for a set of posts.
	 *
	 * @param array<int,int|string|null> $postIds Post ids.
	 *
	 * @return array<int,string>
	 */
	public static function editUrls( array $postIds ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $postIds ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$urls = [];

		foreach ( $ids as $id ) {
			$url = self::editUrl( $id );

			if ( null !== $url ) {
				$urls[ $id ] = $url;
			}
		}

		return $urls;
	}

	/**
	 * The edit URL for one post, if this user may edit it.
	 *
	 * @param int $postId Post id.
	 */
	public static function editUrl( int $postId ): ?string {
		if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
			return null;
		}

		$url = get_edit_post_link( $postId, 'raw' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Try to find the post behind a path.
	 *
	 * Used only for rows whose post id is still null - a page first recorded by
	 * a beacon, where PHP never ran and never resolved one. Memoised because
	 * `url_to_postid()` parses the rewrite rules and can query.
	 *
	 * @param string $path Stored path.
	 */
	public static function resolvePath( string $path ): ?int {
		if ( '' === $path || str_contains( $path, '?' ) ) {
			return null;
		}

		$key    = 'u2p:' . md5( $path );
		$cached = wp_cache_get( $key, 'honest-analytics' );

		if ( is_numeric( $cached ) ) {
			return (int) $cached > 0 ? (int) $cached : null;
		}

		$postId = (int) url_to_postid( home_url( $path ) );

		wp_cache_set( $key, $postId, 'honest-analytics', HOUR_IN_SECONDS );

		return $postId > 0 ? $postId : null;
	}
}
