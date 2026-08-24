<?php
/**
 * The "Views" column on post list screens.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Posts;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Support\Format;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many people read each post, beside the post.
 *
 * WordPress renders list rows one at a time, so the figures for the whole page
 * are fetched in a single query the first time a cell asks and memoised for the
 * rest of the request. Without that, a hundred-row list is a hundred queries.
 */
final class ViewsColumn {

	public const COLUMN = 'honest_views';

	/**
	 * Views for this request's posts.
	 *
	 * @var array<int,int>|null
	 */
	private static ?array $memo = null;

	/**
	 * Attach the hooks for every public post type.
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'attach' ] );
	}

	/**
	 * Attach to each post type's list table.
	 */
	public static function attach(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}

			add_filter( "manage_{$type}_posts_columns", [ self::class, 'addColumn' ] );
			add_action( "manage_{$type}_posts_custom_column", [ self::class, 'renderColumn' ], 10, 2 );
		}
	}

	/**
	 * Add the column header.
	 *
	 * @param array<string,string> $columns Columns.
	 *
	 * @return array<string,string>
	 */
	public static function addColumn( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Views (30d)', 'honest-analytics' );

		return $columns;
	}

	/**
	 * Render a cell.
	 *
	 * @param string $column  Column name.
	 * @param int    $postId  Post id.
	 */
	public static function renderColumn( string $column, int $postId ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$views = self::views()[ $postId ] ?? 0;

		if ( $views <= 0 ) {
			echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' .
				esc_html__( 'No views recorded', 'honest-analytics' ) . '</span>';

			return;
		}

		echo esc_html( Format::count( $views ) );
	}

	/**
	 * Views for every post on this list page.
	 *
	 * @return array<int,int>
	 */
	private static function views(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$query = $GLOBALS['wp_query'] ?? null;
		$ids   = [];

		if ( is_object( $query ) && isset( $query->posts ) && is_array( $query->posts ) ) {
			foreach ( $query->posts as $post ) {
				// WP_Query fills `posts` with WP_Post objects, bare ids or
				// id=>parent rows depending on the `fields` argument another
				// plugin may have filtered in. Reading the property when it is
				// there and casting when it is not covers all three.
				$ids[] = isset( $post->ID ) ? (int) $post->ID : (int) $post;
			}
		}

		self::$memo = [] === $ids
			? []
			: Plugin::instance()->stats()->viewsByPost(
				get_current_blog_id(),
				array_slice( $ids, 0, 200 ),
				DateRange::fromPreset( '30d' )
			);

		return self::$memo;
	}
}
