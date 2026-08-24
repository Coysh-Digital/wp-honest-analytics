<?php
/**
 * Crawler detection.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Bots;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps machines out of the human reports.
 *
 * Without this a site with 400 real visitors and 3,000 bot requests reports
 * 3,400, and every figure on every screen is wrong in a way that still looks
 * entirely plausible. What is excluded is counted separately, so the gap
 * between the server log and the dashboard has an answer.
 *
 * Only a normalised crawler name is ever stored - the fragment of the user
 * agent that identified it - never the whole string.
 */
final class BotFilter {

	/**
	 * Headless browsers, test runners and cache preloaders.
	 *
	 * The WordPress-specific entries matter more than they look: a page cache
	 * warming itself is a request that renders the page, would otherwise be
	 * counted, and would attach its nonce to a view no person ever made.
	 *
	 * @var string[]
	 */
	private const AUTOMATION_MARKERS = [
		'headlesschrome',
		'phantomjs',
		'electron/',
		'puppeteer',
		'playwright',
		'selenium',
		'webdriver',
		'cypress',
		'lighthouse',
		'chrome-lighthouse',
		'pagespeed',
		'gtmetrix',
		'pingdom',
		'wp rocket/preload',
		'wp-rocket',
		'lscache_runner',
		'litespeed-cache',
		'w3 total cache',
		'nitropack',
		'sg-optimizer',
		'wordpress/',
		'wp_is_mobile',
		'curl/',
		'wget/',
		'python-requests',
		'go-http-client',
		'java/',
		'okhttp',
		'axios/',
		'node-fetch',
		'guzzlehttp',
	];

	/**
	 * The library detector, when it is installed.
	 */
	private ?CrawlerDetect $detector = null;

	/**
	 * The last verdict, keyed on what it was reached from.
	 *
	 * One request asks this of the same user agent three or four times - the
	 * shutdown runner, the trackability rules, and the crawler capture each
	 * ask in turn - and the library answer behind it is a regular expression
	 * several thousand alternatives long. Remembering one answer is the
	 * difference between running that once per request and running it four
	 * times.
	 *
	 * @var array{0:string,1:bool}|null
	 */
	private ?array $lastVerdict = null;

	/**
	 * Whether a request came from a machine.
	 *
	 * @param string               $userAgent User agent string.
	 * @param array<string,string> $headers   Request headers, lower-cased keys.
	 */
	public function isBot( string $userAgent, array $headers = [] ): bool {
		$userAgent = trim( $userAgent );
		$memoKey   = $userAgent . "\0" . trim( $headers['accept-language'] ?? '' );

		if ( null !== $this->lastVerdict && $this->lastVerdict[0] === $memoKey ) {
			return $this->lastVerdict[1];
		}

		$verdict           = $this->decide( $userAgent, $headers );
		$this->lastVerdict = [ $memoKey, $verdict ];

		return $verdict;
	}

	/**
	 * The verdict itself.
	 *
	 * @param string               $userAgent Trimmed user agent string.
	 * @param array<string,string> $headers   Request headers, lower-cased keys.
	 */
	private function decide( string $userAgent, array $headers ): bool {

		// A real browser always sends one. Nothing that omits it is a visitor.
		if ( '' === $userAgent ) {
			return true;
		}

		if ( $this->detector()?->isCrawler( $userAgent ) ) {
			return true;
		}

		$lower = strtolower( $userAgent );

		foreach ( self::AUTOMATION_MARKERS as $marker ) {
			if ( str_contains( $lower, $marker ) ) {
				return true;
			}
		}

		if ( $this->matchesFallbackPattern( $lower ) ) {
			return true;
		}

		// The aggressive one, and the usual reason a hand-rolled test request
		// records nothing: browsers send Accept-Language, scripts almost never
		// do. It is documented in the troubleshooting guide for exactly that
		// reason.
		$acceptLanguage = $headers['accept-language'] ?? '';

		if ( '' === trim( $acceptLanguage ) ) {
			return true;
		}

		/**
		 * Filters the crawler verdict for a request.
		 *
		 * @param bool                 $isBot     Whether this looks like a machine.
		 * @param string               $userAgent User agent string.
		 * @param array<string,string> $headers   Request headers.
		 */
		return (bool) apply_filters( 'honest_analytics_is_bot', false, $userAgent, $headers );
	}

	/**
	 * The name to record for a crawler.
	 *
	 * Bounded on purpose: the alternative is storing whole user-agent strings,
	 * which is unbounded cardinality and mild fingerprinting for no gain.
	 *
	 * @param string $userAgent User agent string.
	 */
	public function crawlerName( string $userAgent ): string {
		$userAgent = trim( $userAgent );

		if ( '' === $userAgent ) {
			return __( 'Unknown', 'honest-analytics' );
		}

		$detector = $this->detector();

		if ( null !== $detector && $detector->isCrawler( $userAgent ) ) {
			$matched = (string) $detector->getMatches();

			if ( '' !== trim( $matched ) ) {
				return self::tidy( $matched );
			}
		}

		$lower = strtolower( $userAgent );

		foreach ( self::AUTOMATION_MARKERS as $marker ) {
			if ( str_contains( $lower, $marker ) ) {
				return self::tidy( rtrim( $marker, '/' ) );
			}
		}

		$name = self::fallbackName( $userAgent );

		return '' !== $name ? self::tidy( $name ) : __( 'Unrecognised automation', 'honest-analytics' );
	}

	/**
	 * The crawler-detect instance, when the library is installed.
	 */
	private function detector(): ?CrawlerDetect {
		if ( null !== $this->detector ) {
			return $this->detector;
		}

		if ( ! class_exists( CrawlerDetect::class ) ) {
			return null;
		}

		$this->detector = new CrawlerDetect();

		return $this->detector;
	}

	/**
	 * A small built-in crawler pattern, for installs without the library.
	 *
	 * @param string $lower Lower-cased user agent.
	 */
	private function matchesFallbackPattern( string $lower ): bool {
		return 1 === preg_match(
			'/(bot|crawl|spider|slurp|scrape|archiver|fetcher|monitor|uptime|validator|preview|feedburner|facebookexternalhit|whatsapp|telegrambot|discordbot|slackbot|bingpreview|yandex|baidu|semrush|ahrefs|mj12|dotbot|petalbot|gptbot|claudebot|ccbot|perplexitybot|applebot|amazonbot|bytespider|dataforseo|screaming frog)/i',
			$lower
		);
	}

	/**
	 * Pull a plausible name out of an unrecognised agent.
	 *
	 * @param string $userAgent User agent string.
	 */
	private static function fallbackName( string $userAgent ): string {
		if ( 1 === preg_match( '/([A-Za-z][A-Za-z0-9._-]*(?:bot|crawler|spider|slurp|fetcher|monitor))/i', $userAgent, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Normalise a crawler name for storage.
	 *
	 * @param string $name Raw name.
	 */
	private static function tidy( string $name ): string {
		$name = preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		$name = is_string( $name ) ? trim( $name ) : '';
		$name = preg_replace( '#[/;].*$#', '', $name );
		$name = is_string( $name ) ? trim( $name ) : '';

		return ucfirst( mb_substr( $name, 0, 100 ) );
	}
}
