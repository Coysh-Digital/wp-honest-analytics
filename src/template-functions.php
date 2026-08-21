<?php
/**
 * The functions a theme is allowed to call.
 *
 * Everything here lives in the global namespace, because a template calling
 * honest_analytics_views() is calling it unqualified. The file is required by
 * the plugin bootstrap rather than autoloaded - functions are not classes, and
 * PSR-4 has nothing to say about them.
 *
 * Every function is guarded with function_exists(), so a site that already
 * defines one of these names keeps its own.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Capture\RequestContext;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'honest_analytics' ) ) {
	/**
	 * The plugin's service registry.
	 *
	 * The one global entry point. Everything else in this file is a
	 * convenience over something reachable from here.
	 *
	 * @return Plugin
	 */
	function honest_analytics(): Plugin {
		return Plugin::instance();
	}
}

if ( ! function_exists( 'honest_analytics_track_event' ) ) {
	/**
	 * Record an event.
	 *
	 * The function form of the `honest_analytics_track_event` action, for the
	 * many cases where a call reads better than a `do_action` - and because a
	 * return value tells the caller whether it was recorded, which an action
	 * cannot.
	 *
	 * Returns false when the site is running the free edition, when events are
	 * switched off, when the visitor has signalled a privacy preference, or
	 * when the request came from a crawler. None of those are errors.
	 *
	 *     honest_analytics_track_event( 'brochure-downloaded' );
	 *     honest_analytics_track_event( 'quote-requested', 250.00 );
	 *
	 * @param string      $name   Event name.
	 * @param float|null  $value  Optional value.
	 * @param string|null $path   Path the event happened on.
	 * @param int|null    $postId Post it relates to.
	 */
	function honest_analytics_track_event( string $name, ?float $value = null, ?string $path = null, ?int $postId = null ): bool {
		return Plugin::instance()->trackEvent( $name, $value, $path, $postId );
	}
}

if ( ! function_exists( 'honest_analytics_views' ) ) {
	/**
	 * How many times a post has been viewed.
	 *
	 * Counts pageviews, not visitors, over the last `$days` days. Returns 0
	 * for a post nobody has read and for a post that has never been tracked -
	 * the two are indistinguishable from the rollups, and pretending otherwise
	 * would mean keeping a row per post whether or not anybody visited.
	 *
	 * @param int|WP_Post|null $post A post, or null for the current one.
	 * @param int              $days How far back to count. Capped at 780.
	 */
	function honest_analytics_views( int|WP_Post|null $post = null, int $days = 30 ): int {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$range  = DateRange::fromParam( max( 1, min( 780, $days ) ) . 'd' );
		$counts = Plugin::instance()->stats()->viewsByPost(
			get_current_blog_id(),
			[ $post->ID ],
			$range
		);

		return (int) ( $counts[ $post->ID ] ?? 0 );
	}
}

if ( ! function_exists( 'honest_analytics_popular_posts' ) ) {
	/**
	 * The most-read published posts.
	 *
	 * Only published posts appear. A draft with traffic - which happens, via
	 * preview links and shared URLs - is not somebody else's business.
	 *
	 * Each row is `[ 'postId' => int, 'title' => string, 'views' => int ]`.
	 *
	 * @param array{days?:int,limit?:int} $args Days to look back, and how many rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	function honest_analytics_popular_posts( array $args = [] ): array {
		$days  = max( 1, min( 780, (int) ( $args['days'] ?? 30 ) ) );
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 5 ) ) );

		$rows = Plugin::instance()->contentStats()->topPosts(
			get_current_blog_id(),
			DateRange::fromParam( $days . 'd' ),
			$limit
		);

		return array_map(
			static fn ( array $row ): array => [
				'postId' => (int) $row['postId'],
				'title'  => (string) $row['title'],
				'views'  => (int) $row['views'],
			],
			$rows
		);
	}
}

if ( ! function_exists( 'honest_analytics_gpc_detected' ) ) {
	/**
	 * Whether this visitor asked not to be counted, and was not.
	 *
	 * For themes that would like to say so, which is a better answer to a
	 * privacy signal than silence.
	 *
	 *     if ( honest_analytics_gpc_detected() ) {
	 *         echo '<p>We are not counting this visit - your browser asked us not to.</p>';
	 *     }
	 */
	function honest_analytics_gpc_detected(): bool {
		$context = RequestContext::current();

		if ( null === $context ) {
			return false;
		}

		$settings = Plugin::instance()->settings();

		return $context->hasPrivacySignal( $settings->honourGpc, $settings->honourDnt );
	}
}

if ( ! function_exists( 'honest_analytics_views_shortcode' ) ) {
	/**
	 * `[honest_analytics_views]` - the view count, formatted.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	function honest_analytics_views_shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'post' => '',
				'days' => '30',
			],
			(array) $atts,
			'honest_analytics_views'
		);

		$post = '' !== $atts['post'] ? (int) $atts['post'] : null;

		return esc_html(
			number_format_i18n(
				honest_analytics_views( $post, (int) $atts['days'] )
			)
		);
	}
}

add_shortcode( 'honest_analytics_views', 'honest_analytics_views_shortcode' );
