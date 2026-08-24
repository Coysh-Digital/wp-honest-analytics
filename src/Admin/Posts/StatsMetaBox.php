<?php
/**
 * The analytics panel in the post editor.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Posts;

use HonestAnalytics\Admin\Assets;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Charts\Sparkline;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How this post is doing, while somebody is editing it.
 *
 * CSS and a server-rendered SVG, no JavaScript. A stats panel is not worth
 * taking somebody's editor down for, so it also renders nothing rather than
 * failing if a query goes wrong.
 */
final class StatsMetaBox {

	/**
	 * Attach the hook.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ], 10, 2 );
	}

	/**
	 * Add the panel.
	 *
	 * @param string $postType Post type.
	 * @param mixed  $post     The post.
	 */
	public static function add( string $postType, mixed $post ): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		$object = get_post_type_object( $postType );

		if ( ! $object || ! $object->public ) {
			return;
		}

		if ( is_object( $post ) && 'auto-draft' === ( $post->post_status ?? '' ) ) {
			return;
		}

		add_meta_box(
			'honest-analytics-post',
			__( 'Analytics, last 30 days', 'honest-analytics' ),
			[ self::class, 'render' ],
			$postType,
			'side',
			'default'
		);
	}

	/**
	 * Render the panel.
	 *
	 * @param mixed $post The post.
	 */
	public static function render( mixed $post ): void {
		Assets::widget();

		// `isset()` rather than `is_object()`: the callback is handed whatever
		// add_meta_box() was registered with, and an object without an ID is
		// as useless here as a string is - asking for the property directly
		// answers both questions at once and never warns.
		$postId = isset( $post->ID ) ? (int) $post->ID : 0;

		if ( $postId <= 0 ) {
			return;
		}

		try {
			$plugin = Plugin::instance();
			$range  = DateRange::fromPreset( '30d' );
			$stats  = $plugin->stats()->postStats( get_current_blog_id(), $postId, $range );

			$permalink = get_permalink( $postId );
			$path      = is_string( $permalink ) ? (string) wp_parse_url( $permalink, PHP_URL_PATH ) : '';
			$path      = '/' !== $path && '' !== $path ? rtrim( $path, '/' ) : '/';

			View::render(
				'metabox/post-stats',
				[
					'stats'     => $stats,
					'sparkline' => Sparkline::path( $stats['series'], 200, 30 ),
					'accuracy'  => $plugin->stats()->uniquesAccuracy(),
					'detailUrl' => add_query_arg(
						[
							'path'  => $path,
							'range' => $range->param,
						],
						menu_page_url( 'honest-analytics-pages', false )
					),
				]
			);
		} catch ( \Throwable ) {
			// Never take somebody's editor down over a stats panel.
			echo '<p class="ha-muted">' . esc_html__( 'Analytics are not available right now.', 'honest-analytics' ) . '</p>';
		}
	}
}
