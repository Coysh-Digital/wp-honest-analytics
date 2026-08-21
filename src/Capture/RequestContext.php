<?php
/**
 * What the request was, snapshotted while WordPress still knows.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Support\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A snapshot of the request, taken at `wp` and read again at `shutdown`.
 *
 * The two moments are far apart and a great deal happens in between. Plugins
 * redirect and exit during `template_redirect`, themes run their own queries,
 * and by the time the response has been sent `is_singular()` may well answer
 * about something else entirely. Everything the capture path needs is therefore
 * recorded while the main query is still the current one, and nothing is asked
 * of WordPress afterwards.
 */
final class RequestContext {

	/**
	 * The snapshot for this request, if one was taken.
	 */
	private static ?self $current = null;

	/**
	 * @param string               $method             Request method; only GET is ever tracked.
	 * @param string               $path               Requested path, before normalisation.
	 * @param string               $queryString        Raw query string, read for campaigns.
	 * @param int|null             $postId             Queried post, when there is one.
	 * @param string               $postType           Its type, for the Content screens.
	 * @param bool                 $isSingular         Whether one post is being viewed.
	 * @param bool                 $isSearch           Whether this is a search result page.
	 * @param string               $searchTerm         The term searched for, stored separately.
	 * @param bool                 $is404              Whether WordPress found nothing.
	 * @param bool                 $isFeed             Whether this is a feed.
	 * @param bool                 $isRobots           Whether this is robots.txt.
	 * @param bool                 $isFavicon          Whether this is the favicon.
	 * @param bool                 $isTrackback        Whether this is a trackback.
	 * @param bool                 $isEmbed            Whether this is an oEmbed frame.
	 * @param bool                 $isPreview          Whether this is a post preview.
	 * @param bool                 $isCustomizePreview Whether the customizer is rendering it.
	 * @param bool                 $isSitemap          Whether this is a sitemap.
	 * @param bool                 $isLoggedIn         Whether somebody is signed in.
	 * @param int                  $userId             Who, so exclusion rules can be applied.
	 * @param string               $userAgent          Parsed and discarded, never stored whole.
	 * @param string               $acceptLanguage     Reduced to a language, never stored whole.
	 * @param string               $referrer           Reduced to a host, never stored whole.
	 * @param bool                 $gpc                Whether Global Privacy Control was sent.
	 * @param bool                 $dnt                Whether Do Not Track was sent.
	 * @param array<string,string> $headers            The request headers we read, lower-cased.
	 */
	private function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly string $queryString,
		public readonly ?int $postId,
		public readonly string $postType,
		public readonly bool $isSingular,
		public readonly bool $isSearch,
		public readonly string $searchTerm,
		public readonly bool $is404,
		public readonly bool $isFeed,
		public readonly bool $isRobots,
		public readonly bool $isFavicon,
		public readonly bool $isTrackback,
		public readonly bool $isEmbed,
		public readonly bool $isPreview,
		public readonly bool $isCustomizePreview,
		public readonly bool $isSitemap,
		public readonly bool $isLoggedIn,
		public readonly int $userId,
		public readonly string $userAgent,
		public readonly string $acceptLanguage,
		public readonly string $referrer,
		public readonly bool $gpc,
		public readonly bool $dnt,
		public readonly array $headers
	) {
	}

	/**
	 * Take the snapshot, unless this is plainly not a page view.
	 *
	 * @param Server|null $server Request environment.
	 */
	public static function capture( ?Server $server = null ): ?self {
		if ( self::isNeverTrackable() ) {
			return null;
		}

		$server = $server ?? new Server();

		if ( 'GET' !== $server->method() ) {
			return null;
		}

		$uri                    = $server->requestUri();
		[ $path, $queryString ] = self::splitUri( $uri );

		$isSingular = function_exists( 'is_singular' ) && is_singular();
		$postId     = self::resolvePostId( $isSingular );
		$headers    = $server->headers();

		self::$current = new self(
			$server->method(),
			$path,
			$queryString,
			$postId,
			$postId ? (string) get_post_type( $postId ) : '',
			$isSingular,
			function_exists( 'is_search' ) && is_search(),
			function_exists( 'get_search_query' ) ? (string) get_search_query( false ) : '',
			function_exists( 'is_404' ) && is_404(),
			function_exists( 'is_feed' ) && is_feed(),
			function_exists( 'is_robots' ) && is_robots(),
			function_exists( 'is_favicon' ) && is_favicon(),
			function_exists( 'is_trackback' ) && is_trackback(),
			function_exists( 'is_embed' ) && is_embed(),
			function_exists( 'is_preview' ) && is_preview(),
			function_exists( 'is_customize_preview' ) && is_customize_preview(),
			self::isSitemapRequest(),
			is_user_logged_in(),
			get_current_user_id(),
			$server->userAgent(),
			$headers['accept-language'] ?? '',
			$server->referrer(),
			'1' === ( $headers['sec-gpc'] ?? '' ),
			'1' === ( $headers['dnt'] ?? '' ),
			$headers
		);

		return self::$current;
	}

	/**
	 * The snapshot for this request, if there is one.
	 */
	public static function current(): ?self {
		return self::$current;
	}

	/**
	 * Replace the snapshot. For tests.
	 *
	 * @param self|null $context Context.
	 */
	public static function set( ?self $context ): void {
		self::$current = $context;
	}

	/**
	 * Requests that could never be a pageview, whatever else is true.
	 *
	 * REST requests die inside `parse_request` and never reach `wp` at all, so
	 * the constant check there is belt and braces rather than the mechanism.
	 */
	private static function isNeverTrackable(): bool {
		if ( is_admin() ) {
			return true;
		}

		foreach ( [ 'REST_REQUEST', 'XMLRPC_REQUEST', 'IFRAME_REQUEST', 'WP_CLI', 'DOING_AUTOSAVE' ] as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return true;
			}
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return true;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}

		if ( function_exists( 'wp_is_xml_request' ) && wp_is_xml_request() ) {
			return true;
		}

		return false;
	}

	/**
	 * The post this request is about, if it is about one.
	 *
	 * `get_queried_object_id()` answers about a term on a category archive and
	 * an author on an author archive, so it is only asked when the answer can
	 * be a post. The posts page is included deliberately: it is a real page
	 * somebody can edit, even though `is_singular()` is false for it.
	 *
	 * @param bool $isSingular Whether this is a singular request.
	 */
	private static function resolvePostId( bool $isSingular ): ?int {
		if ( $isSingular ) {
			$id = (int) get_queried_object_id();

			return $id > 0 ? $id : null;
		}

		if ( function_exists( 'is_home' ) && is_home() ) {
			$pageForPosts = (int) get_option( 'page_for_posts' );

			if ( $pageForPosts > 0 ) {
				return $pageForPosts;
			}
		}

		return null;
	}

	/**
	 * Whether this request is one of core's sitemaps.
	 *
	 * They exit inside `template_redirect`, so they never reach the point where
	 * a template would have rendered; the flag keeps them out regardless.
	 */
	private static function isSitemapRequest(): bool {
		if ( ! function_exists( 'get_query_var' ) ) {
			return false;
		}

		return '' !== (string) get_query_var( 'sitemap' )
			|| '' !== (string) get_query_var( 'sitemap-stylesheet' )
			|| '' !== (string) get_query_var( 'sitemap-subtype' );
	}

	/**
	 * Split a request URI into path and query string.
	 *
	 * @param string $uri Request URI.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function splitUri( string $uri ): array {
		$position = strpos( $uri, '?' );

		if ( false === $position ) {
			return [ $uri, '' ];
		}

		return [ substr( $uri, 0, $position ), substr( $uri, $position + 1 ) ];
	}

	/**
	 * Whether the visitor has asked, in a legally recognised way, not to be tracked.
	 *
	 * @param bool $honourGpc Whether GPC is honoured.
	 * @param bool $honourDnt Whether legacy DNT is honoured.
	 */
	public function hasPrivacySignal( bool $honourGpc, bool $honourDnt ): bool {
		if ( $honourGpc && $this->gpc ) {
			return true;
		}

		return $honourDnt && $this->dnt;
	}
}
