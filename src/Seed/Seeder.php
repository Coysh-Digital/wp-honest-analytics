<?php
/**
 * Synthetic traffic, for development.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Seed;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Capture\CaptureService;
use HonestAnalytics\Channels\Campaign;
use HonestAnalytics\Devices\Device;
use HonestAnalytics\Devices\DeviceParser;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Plugin;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills a development site with traffic that behaves like traffic.
 *
 * This exists to answer questions a handful of manual page loads cannot: does
 * the dashboard still render at four hundred days, does compaction actually
 * shrink anything, does the chart look right when a weekend dips, does the
 * storage claim in the documentation survive contact with a hundred thousand
 * events. So the data has a weekly rhythm, a slow upward trend, a long tail of
 * pages, and visitors who look at more than one thing.
 *
 * It goes through the real pipeline - writer, spool, drain, rollup - rather than
 * inserting rollup rows directly. That is deliberate and it has already earned
 * its keep: seeding ninety days is what surfaced a visitor hash of sixteen
 * digits being cast to an integer by PHP's array-key rules and fataling the
 * drain, roughly one visitor in forty-three thousand. Nothing short of volume
 * finds that. Hence the `strval()` calls below, which look redundant and are
 * not.
 */
final class Seeder {

	/** Visits per visitor, on average. */
	private const PAGES_PER_VISIT = 2.4;


	private Settings $settings;

	/**
	 * @param Settings|null $settings Settings.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? Plugin::instance()->settings();
	}

	/**
	 * Generate and record traffic.
	 *
	 * @param array<string,mixed> $options days, perDay, content, force, now, progress.
	 *
	 * @return array<string,int|float|string>
	 */
	public function run( array $options = [] ): array {
		$days     = max( 1, min( 400, (int) ( $options['days'] ?? 30 ) ) );
		$perDay   = max( 1, min( 20000, (int) ( $options['perDay'] ?? 200 ) ) );
		$force    = (bool) ( $options['force'] ?? false );
		$now      = (int) ( $options['now'] ?? time() );
		$progress = $options['progress'] ?? null;

		if ( ! $force && ! $this->isSafeEnvironment() ) {
			return [
				'error' => __( 'This looks like a production site. Seeding writes invented traffic into the reports; pass --force if that is really what you want.', 'honest-analytics' ),
			];
		}

		$siteId  = get_current_blog_id();
		$paths   = $this->paths( (bool) ( $options['content'] ?? false ) );
		$writer  = Plugin::instance()->writer();
		$drainer = Plugin::instance()->drainer();
		$started = microtime( true );

		$hits     = 0;
		$visitors = 0;

		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$midnight = Timezone::at( $now )->modify( '-' . $offset . ' days' )->setTime( 0, 0 )->getTimestamp();
			$target   = $this->volumeFor( $midnight, $perDay, $days - $offset, $days );

			$dayHits     = 0;
			$dayVisitors = 0;

			while ( $dayHits < $target ) {
				$visit = $this->visit( $siteId, $midnight, $paths );

				foreach ( $visit as $hit ) {
					$writer->write( $hit );
				}

				$dayHits += count( $visit );
				++$dayVisitors;
			}

			foreach ( $this->crawlerHits( $siteId, $midnight, $paths ) as $hit ) {
				$writer->write( $hit );
				++$dayHits;
			}

			$hits     += $dayHits;
			$visitors += $dayVisitors;

			if ( is_callable( $progress ) ) {
				$progress( $days - $offset, $days, $dayHits );
			}

			// Drained a day at a time, with the clock wound forward past the
			// session window, so each day's visits are closed and counted before
			// the next day's are written. Left to the end it would be one
			// enormous batch and every session would look like it happened at
			// once.
			$drainer->run( $midnight + 86400 + $this->settings->sessionWindowSeconds() + 60 );
		}

		$drainer->run( $now );

		return [
			'days'     => $days,
			'hits'     => $hits,
			'visitors' => $visitors,
			'seconds'  => round( microtime( true ) - $started, 1 ),
		];
	}

	/**
	 * One visitor's whole visit.
	 *
	 * @param int                              $siteId   Site ID.
	 * @param int                              $midnight Start of the day.
	 * @param array<int,array{0:string,1:int}> $paths    Candidate paths.
	 *
	 * @return Hit[]
	 */
	private function visit( int $siteId, int $midnight, array $paths ): array {
		// A visitor hash is sixteen hexadecimal characters, which means roughly
		// one in forty-three thousand is all digits. `strval()` here, and at
		// every point one of these is used as an array key, is what stops PHP
		// turning it into an integer.
		$visitorHash = strval( bin2hex( random_bytes( 8 ) ) );
		$sessionKey  = strval( substr( hash( 'sha256', $visitorHash . '|' . $siteId ), 0, 16 ) );

		$device   = (string) Device::fromUserAgent( new DeviceParser(), $this->userAgent() );
		$referrer = $this->referrer();
		$campaign = $this->campaign();
		$country  = $this->country();

		$pages     = max( 1, (int) round( $this->skewed() * self::PAGES_PER_VISIT * 2 ) );
		$timestamp = $midnight + $this->timeOfDay();

		$hits = [];

		for ( $page = 0; $page < $pages; $page++ ) {
			[ $path, $postId ] = $paths[ array_rand( $paths ) ];

			$hits[] = new Hit(
				siteId: $siteId,
				path: $path,
				visitorHash: $visitorHash,
				sessionKey: $sessionKey,
				timestamp: $timestamp,
				postId: $postId > 0 ? $postId : null,
				// Only the first page of a visit has an external referrer. The
				// rest came from this site, which is not a traffic source.
				referrer: 0 === $page ? $referrer : '',
				device: $device,
				dwellMs: random_int( 4000, 240000 ),
				campaign: 0 === $page ? $campaign : null,
				countryCode: $country[0],
				region: $country[1],
				scrollBucket: $this->scroll()
			);

			$timestamp += random_int( 15, 400 );
		}

		foreach ( $this->interactions( $siteId, $visitorHash, $sessionKey, $timestamp, $hits[0]->path, $device ) as $hit ) {
			$hits[] = $hit;
		}

		return $hits;
	}

	/**
	 * Events, outbound clicks and downloads, when the edition has them.
	 *
	 * @param int    $siteId      Site ID.
	 * @param string $visitorHash Visitor hash.
	 * @param string $sessionKey  Session key.
	 * @param int    $timestamp   When.
	 * @param string $path        Where.
	 * @param string $device      The reduced device signature.
	 *
	 * @return Hit[]
	 */
	private function interactions( int $siteId, string $visitorHash, string $sessionKey, int $timestamp, string $path, string $device ): array {
		if ( ! Edition::isPro() || ! $this->settings->enableEvents || random_int( 1, 100 ) > 12 ) {
			return [];
		}

		$kinds = [ Hit::KIND_EVENT, Hit::KIND_OUTBOUND, Hit::KIND_DOWNLOAD ];
		$kind  = $kinds[ array_rand( $kinds ) ];

		$names = [ 'newsletter-signup', 'quote-requested', 'brochure-downloaded', 'video-played', 'form submission' ];

		return [
			new Hit(
				siteId: $siteId,
				path: $path,
				visitorHash: strval( $visitorHash ),
				sessionKey: strval( $sessionKey ),
				timestamp: $timestamp,
				device: $device,
				countView: false,
				kind: $kind,
				eventName: Hit::KIND_EVENT === $kind ? $names[ array_rand( $names ) ] : null,
				eventValue: Hit::KIND_EVENT === $kind && 0 === random_int( 0, 2 ) ? (float) random_int( 25, 900 ) : null,
				target: Hit::KIND_EVENT === $kind ? null : 'https://example.com/' . ( Hit::KIND_DOWNLOAD === $kind ? 'brochure.pdf' : 'partner' )
			),
		];
	}

	/**
	 * A few crawler requests, so the Crawlers screen has something honest on it.
	 *
	 * @param int                              $siteId   Site ID.
	 * @param int                              $midnight Start of the day.
	 * @param array<int,array{0:string,1:int}> $paths    Candidate paths.
	 *
	 * @return Hit[]
	 */
	private function crawlerHits( int $siteId, int $midnight, array $paths ): array {
		if ( ! $this->settings->trackCrawlers ) {
			return [];
		}

		$crawlers = [ 'Googlebot', 'bingbot', 'DuckDuckBot', 'AhrefsBot', 'GPTBot' ];
		$hits     = [];

		foreach ( $crawlers as $crawler ) {
			$requests = random_int( 0, 40 );

			for ( $i = 0; $i < $requests; $i++ ) {
				[ $path ] = $paths[ array_rand( $paths ) ];

				$hits[] = new Hit(
					siteId: $siteId,
					path: $path,
					visitorHash: CaptureService::CRAWLER_HASH,
					sessionKey: CaptureService::CRAWLER_HASH,
					timestamp: $midnight + random_int( 0, 86399 ),
					countView: false,
					kind: Hit::KIND_CRAWLER,
					eventName: $crawler
				);
			}
		}

		return $hits;
	}

	/**
	 * The paths to spread traffic over.
	 *
	 * @param bool $useContent Whether to use the site's real posts.
	 *
	 * @return array<int,array{0:string,1:int}> Path and post id.
	 */
	private function paths( bool $useContent ): array {
		$paths = [
			[ '/', 0 ],
			[ '/about', 0 ],
			[ '/contact', 0 ],
			[ '/pricing', 0 ],
			[ '/blog', 0 ],
		];

		if ( ! $useContent ) {
			return $paths;
		}

		// A fresh WordPress has one post and one page, which demonstrates
		// nothing about the Content screens. Idempotent: a second run adds
		// nothing.
		DemoContent::install();

		$posts = get_posts(
			[
				'post_type'        => [ 'post', 'page' ],
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'suppress_filters' => false,
			]
		);

		foreach ( $posts as $post ) {
			$path = wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );

			if ( is_string( $path ) && '' !== $path ) {
				$paths[] = [ '/' . trim( $path, '/' ), (int) $post->ID ];
			}
		}

		return $paths;
	}

	/**
	 * How many hits a given day should get.
	 *
	 * Quieter at weekends, and slowly growing, because a chart of uniform noise
	 * tells nobody whether the chart works.
	 *
	 * @param int $midnight Start of the day.
	 * @param int $perDay   Baseline volume.
	 * @param int $index    Which day this is, counting from the oldest.
	 * @param int $total    How many days in all.
	 */
	private function volumeFor( int $midnight, int $perDay, int $index, int $total ): int {
		$weekday = (int) Timezone::at( $midnight )->format( 'N' );
		$weekend = $weekday >= 6 ? 0.62 : 1.0;
		$growth  = 0.7 + ( 0.6 * ( $index / max( 1, $total ) ) );
		$noise   = random_int( 85, 115 ) / 100;

		return max( 1, (int) round( $perDay * $weekend * $growth * $noise ) );
	}

	/**
	 * A second-of-day, weighted towards waking hours.
	 */
	private function timeOfDay(): int {
		$hour = [ 7, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14, 15, 15, 16, 16, 17, 18, 19, 20, 21, 22, 0, 3 ];

		return $hour[ array_rand( $hour ) ] * 3600 + random_int( 0, 3599 );
	}

	/**
	 * A number between roughly 0 and 1, skewed low.
	 *
	 * Most visits are one page. A uniform distribution would give every site a
	 * suspiciously healthy pages-per-visit figure.
	 */
	private function skewed(): float {
		return min( 1.0, abs( random_int( 0, 1000 ) / 1000 ) ** 2.2 + 0.08 );
	}

	/**
	 * A browser.
	 */
	private function userAgent(): string {
		$agents = [
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
			'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		];

		return $agents[ array_rand( $agents ) ];
	}

	/**
	 * Where the visit came from.
	 */
	private function referrer(): string {
		$referrers = [
			'',
			'',
			'',
			'https://www.google.com/',
			'https://www.google.co.uk/',
			'https://duckduckgo.com/',
			'https://www.bing.com/',
			'https://news.ycombinator.com/',
			'https://www.linkedin.com/feed/',
			'https://mastodon.social/explore',
			'https://example.org/round-up',
		];

		return $referrers[ array_rand( $referrers ) ];
	}

	/**
	 * A campaign, occasionally.
	 */
	private function campaign(): ?Campaign {
		if ( ! $this->settings->enableCampaigns || random_int( 1, 100 ) > 18 ) {
			return null;
		}

		$campaigns = [
			'utm_source=newsletter&utm_medium=email&utm_campaign=spring-update',
			'utm_source=twitter&utm_medium=social&utm_campaign=launch',
			'utm_source=google&utm_medium=cpc&utm_campaign=brand&utm_term=analytics',
			'utm_source=partner&utm_medium=referral&utm_campaign=directory',
		];

		return Campaign::fromQueryString( $campaigns[ array_rand( $campaigns ) ] );
	}

	/**
	 * A country and region.
	 *
	 * @return array{0:string,1:string}
	 */
	private function country(): array {
		if ( ! $this->settings->enableGeo ) {
			return [ '', '' ];
		}

		$places = [
			[ 'GB', 'ENG' ],
			[ 'GB', 'SCT' ],
			[ 'US', 'CA' ],
			[ 'US', 'NY' ],
			[ 'DE', 'BE' ],
			[ 'FR', 'IDF' ],
			[ 'AU', 'NSW' ],
			[ 'IE', 'L' ],
		];

		return $places[ array_rand( $places ) ];
	}

	/**
	 * How far down the page a visitor read.
	 */
	private function scroll(): ?int {
		if ( ! Edition::isPro() || ! $this->settings->enableEvents || ! $this->settings->trackScroll ) {
			return null;
		}

		$buckets = [ 25, 25, 25, 50, 50, 75, 100 ];

		return $buckets[ array_rand( $buckets ) ];
	}

	/**
	 * Whether this is somewhere invented traffic belongs.
	 */
	private function isSafeEnvironment(): bool {
		if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) {
			return false;
		}

		return defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' );
	}
}
