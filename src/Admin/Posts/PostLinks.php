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

		// One query for the lot. Every editUrl() below asks
		// current_user_can( 'edit_post', $id ), which sends map_meta_cap to
		// get_post() - and an unprimed get_post() is a query each. On a Pages
		// screen showing two hundred rows that was two hundred single-row
		// lookups to decide which titles become links. Terms and meta are not
		// wanted, hence both flags false.
		_prime_post_caches( $ids, false, false );

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
}
