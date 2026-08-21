<?php
/**
 * Whether a request should be counted.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Bots\BotFilter;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The decision, in one place, with a reason attached.
 *
 * The reason matters: "nothing is being recorded" is the single most common
 * support question for any analytics plugin, and a diagnostic that can say
 * *which* rule declined a request turns an afternoon into a minute.
 */
final class Trackability {

	private Settings $settings;
	private BotFilter $bots;
	private PathNormalizer $paths;

	/**
	 * Why the last request was declined.
	 */
	private string $reason = '';

	/**
	 * @param Settings       $settings Settings.
	 * @param BotFilter      $bots     Bot filter.
	 * @param PathNormalizer $paths    Path normaliser.
	 */
	public function __construct( Settings $settings, BotFilter $bots, PathNormalizer $paths ) {
		$this->settings = $settings;
		$this->bots     = $bots;
		$this->paths    = $paths;
	}

	/**
	 * Whether a request is a human pageview worth counting.
	 *
	 * @param RequestContext $context  Request snapshot.
	 * @param ResponseFacts  $response Response facts.
	 */
	public function isTrackable( RequestContext $context, ResponseFacts $response ): bool {
		if ( ! $this->isPageRequest( $context, $response ) ) {
			return false;
		}

		if ( $context->hasPrivacySignal( $this->settings->honourGpc, $this->settings->honourDnt ) ) {
			return $this->decline( 'privacy-signal' );
		}

		if ( $this->isExcludedUser( $context ) ) {
			return $this->decline( 'excluded-user' );
		}

		if ( $this->isCrawler( $context ) ) {
			// With crawler blocking off, detection still happens - it simply
			// stops excluding anything, and the bot's requests land in every
			// human figure on every screen. The Crawlers report says so.
			return $this->settings->blockCrawlers ? $this->decline( 'crawler' ) : $this->allow();
		}

		return $this->allow();
	}

	/**
	 * Whether the request was a page being served at all.
	 *
	 * Shared with crawler capture, which cares about everything here except the
	 * bot check and the privacy signal - a crawler has no privacy to respect
	 * and is the thing being counted.
	 *
	 * @param RequestContext $context  Request snapshot.
	 * @param ResponseFacts  $response Response facts.
	 */
	public function isPageRequest( RequestContext $context, ResponseFacts $response ): bool {
		if ( 'GET' !== $context->method ) {
			return $this->decline( 'not-get' );
		}

		if ( ! $response->isOk() ) {
			return $this->decline( 'status-' . $response->status );
		}

		if ( ! $response->isHtml() ) {
			return $this->decline( 'not-html' );
		}

		// The keys are reason slugs, and one of them is all digits. PHP casts an
		// all-digit array key to an integer whatever the quotes say, so `'404'`
		// arrives here as int(404) and has to be cast back before it reaches a
		// string parameter. Under strict_types the alternative is a TypeError
		// on every 404 the site serves.
		foreach (
			[
				'404'               => $context->is404,
				'feed'              => $context->isFeed,
				'robots'            => $context->isRobots,
				'favicon'           => $context->isFavicon,
				'trackback'         => $context->isTrackback,
				'embed'             => $context->isEmbed,
				'preview'           => $context->isPreview,
				'customize-preview' => $context->isCustomizePreview,
				'sitemap'           => $context->isSitemap,
			] as $reason => $flag
		) {
			if ( $flag ) {
				return $this->decline( (string) $reason );
			}
		}

		$path = $this->paths->normalize( $context->path, $context->queryString );

		if ( $this->paths->isExcluded( $path ) ) {
			return $this->decline( 'excluded-path' );
		}

		return true;
	}

	/**
	 * Whether this request came from a machine.
	 *
	 * @param RequestContext $context Request snapshot.
	 */
	public function isCrawler( RequestContext $context ): bool {
		return $this->bots->isBot( $context->userAgent, $context->headers );
	}

	/**
	 * Whether this visitor is somebody who works here.
	 *
	 * `edit_posts` is the line rather than a role list: it covers administrators,
	 * editors, authors and contributors, and leaves subscribers and shop
	 * customers counted, which is what people mean by "don't count me".
	 *
	 * @param RequestContext $context Request snapshot.
	 */
	private function isExcludedUser( RequestContext $context ): bool {
		if ( ! $context->isLoggedIn || $context->userId <= 0 ) {
			return false;
		}

		$excluded = $this->settings->excludeLoggedIn && user_can( $context->userId, 'edit_posts' );

		/**
		 * Filters whether a signed-in visitor is left out of the reports.
		 *
		 * @param bool $excluded Whether to exclude.
		 * @param int  $userId   User ID.
		 */
		return (bool) apply_filters( 'honest_analytics_exclude_user', $excluded, $context->userId );
	}

	/**
	 * Why the last request was declined.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Record a decline reason and return false.
	 *
	 * @param string $reason Reason slug.
	 */
	private function decline( string $reason ): bool {
		$this->reason = $reason;

		return false;
	}

	/**
	 * Clear the reason and return true.
	 */
	private function allow(): bool {
		$this->reason = '';

		return true;
	}
}
