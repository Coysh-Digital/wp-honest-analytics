<?php
/**
 * Referrer to channel classification.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Channels;

use HonestAnalytics\Support\Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a referring host into one of six channels.
 *
 * Only the host is ever stored, never the full referrer URL - a search results
 * URL carries the query someone typed, which is not something an analytics
 * plugin has any business keeping.
 */
final class ChannelClassifier {

	/**
	 * Hosts treated as search engines. A trailing dot matches any TLD.
	 *
	 * @var string[]
	 */
	private const SEARCH_HOSTS = [
		'google.',
		'bing.com',
		'duckduckgo.com',
		'lite.duckduckgo.com',
		'search.yahoo.',
		'yandex.',
		'baidu.com',
		'ecosia.org',
		'startpage.com',
		'search.brave.com',
		'qwant.com',
		'search.marginalia.nu',
		'searx.',
		'perplexity.ai',
		'chatgpt.com',
		'chat.openai.com',
		'claude.ai',
		'copilot.microsoft.com',
		'gemini.google.com',
	];

	/**
	 * Hosts treated as social networks.
	 *
	 * @var string[]
	 */
	private const SOCIAL_HOSTS = [
		'facebook.com',
		'fb.com',
		'm.facebook.com',
		'l.facebook.com',
		'instagram.com',
		'l.instagram.com',
		'twitter.com',
		'x.com',
		't.co',
		'linkedin.com',
		'lnkd.in',
		'reddit.com',
		'out.reddit.com',
		'pinterest.',
		'youtube.com',
		'youtu.be',
		'tiktok.com',
		'threads.net',
		'threads.com',
		'mastodon.',
		'bsky.app',
		'bsky.social',
		'tumblr.com',
		'quora.com',
		'whatsapp.com',
		'telegram.org',
		't.me',
		'discord.com',
		'news.ycombinator.com',
		'slack.com',
	];

	/**
	 * Memoised rules, including anything a site added.
	 *
	 * @var array<string,Channel>|null
	 */
	private ?array $rules = null;

	/**
	 * Classify a session.
	 *
	 * @param string $referrer    The referrer the session arrived by.
	 * @param bool   $hasCampaign Whether the session carried campaign tags.
	 */
	public function classify( string $referrer, bool $hasCampaign = false ): Channel {
		if ( $hasCampaign ) {
			return Channel::Campaign;
		}

		$host = Url::host( $referrer );

		if ( null === $host ) {
			return Channel::Direct;
		}

		foreach ( $this->rules() as $fragment => $channel ) {
			if ( self::matches( $host, $fragment ) ) {
				return $channel;
			}
		}

		return Channel::Referral;
	}

	/**
	 * The host of a referrer, or null when there isn't one.
	 *
	 * @param string $referrer Referrer URL.
	 */
	public function host( string $referrer ): ?string {
		return Url::host( $referrer );
	}

	/**
	 * The classification rules.
	 *
	 * @return array<string,Channel>
	 */
	private function rules(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$rules = [];

		foreach ( self::SEARCH_HOSTS as $fragment ) {
			$rules[ $fragment ] = Channel::Search;
		}

		foreach ( self::SOCIAL_HOSTS as $fragment ) {
			$rules[ $fragment ] = Channel::Social;
		}

		/**
		 * Filters the referrer host to channel rules.
		 *
		 * Keys are host fragments; a trailing dot ("google.") matches any TLD.
		 * Values are Channel cases.
		 *
		 * @param array<string,Channel> $rules Rules.
		 */
		$filtered = apply_filters( 'honest_analytics_channel_rules', $rules );

		$this->rules = [];

		foreach ( (array) $filtered as $fragment => $channel ) {
			if ( is_string( $fragment ) && $channel instanceof Channel ) {
				$this->rules[ strtolower( $fragment ) ] = $channel;
			}
		}

		return $this->rules;
	}

	/**
	 * Whether a host matches a rule fragment.
	 *
	 * @param string $host     Lower-case host.
	 * @param string $fragment Rule fragment.
	 */
	private static function matches( string $host, string $fragment ): bool {
		if ( str_ends_with( $fragment, '.' ) ) {
			$bare = rtrim( $fragment, '.' );

			return $host === $bare
				|| str_starts_with( $host, $fragment )
				|| str_contains( $host, '.' . $fragment );
		}

		return $host === $fragment || str_ends_with( $host, '.' . $fragment );
	}
}
