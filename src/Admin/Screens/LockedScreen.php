<?php
/**
 * A Pro report, described rather than shown.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stands where a Pro report would be, in the free build.
 *
 * The free build does not contain the Pro reports. They are removed at package
 * time rather than switched off, so there is nothing here to unlock, no key to
 * enter and no code sitting dormant behind a check - which is both what the
 * plugin directory requires and the honest arrangement anyway.
 *
 * What this screen does is answer the question its menu item raises: what would
 * be on this page, and is that worth paying for. It shows **no figures, real or
 * invented**. A skeleton filled with plausible numbers would be a lie about the
 * site's own data, and that is a worse trade than an empty page.
 *
 * It is deliberately not a Pro screen. `isPro()` returns false, because this
 * one ships in Lite and is the only thing a Lite site can reach at these
 * addresses.
 */
final class LockedScreen extends Screen {

	/**
	 * Where somebody who wants the paid edition should go.
	 *
	 * With the hyphen. The unhyphenated spelling belongs to somebody else and
	 * has been parked since 2015, and this link ships in the free edition, so
	 * getting it wrong sends every Lite site that clicks a locked report to a
	 * stranger's parking page instead of to the pricing page.
	 */
	private const HOME = 'https://honest-analytics.com/pricing';

	/**
	 * Which report this stands in for.
	 */
	private string $key;

	/**
	 * @param string $key One of the keys in features().
	 */
	private function __construct( string $key ) {
		$this->key = $key;
	}

	/**
	 * A stand-in for one report, or null if there is no such thing.
	 *
	 * @param string $key Report key.
	 */
	public static function for( string $key ): ?self {
		return isset( self::features()[ $key ] ) ? new self( $key ) : null;
	}

	/**
	 * The page slug.
	 *
	 * The same slug the real screen uses, so that a bookmark, a support article
	 * or a link in an email survives the upgrade rather than becoming a 404 the
	 * moment somebody pays.
	 */
	public function slug(): string {
		return 'honest-analytics-' . $this->key;
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return (string) ( self::features()[ $this->key ]['title'] ?? '' );
	}

	/**
	 * The menu label, with the edition marked on it.
	 *
	 * WordPress prints a menu title as markup, which is what makes the badge
	 * possible. Nothing here comes from a request; it is a fixed string and a
	 * translated word.
	 */
	public function menuLabel(): string {
		return $this->title()
			. ' <span class="ha-menu-pro" aria-hidden="true">'
			. esc_html__( 'Pro', 'honest-analytics' )
			. '</span><span class="screen-reader-text"> '
			. esc_html__( '(part of Pro)', 'honest-analytics' )
			. '</span>';
	}

	/**
	 * No range, no export, no charts.
	 *
	 * A date range that changes nothing on the page is worse than no date
	 * range: it invites somebody to conclude the report is broken rather than
	 * absent.
	 */
	public function hasRange(): bool {
		return false;
	}

	/**
	 * No charts to draw.
	 */
	public function usesCharts(): bool {
		return false;
	}

	/**
	 * Prose, so the narrower measure.
	 */
	public function maxWidth(): int {
		return 900;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$feature = self::features()[ $this->key ];

		View::render(
			'admin/locked',
			[
				'title'   => $feature['title'],
				'lede'    => $feature['lede'],
				'shows'   => $feature['shows'],
				'note'    => $feature['note'],
				'homeUrl' => self::homeUrl(),
			]
		);
	}

	/**
	 * Where the one link on the page points.
	 */
	public static function homeUrl(): string {
		/**
		 * Filters the address the free edition points at for the paid one.
		 *
		 * @param string $url Absolute URL.
		 */
		return (string) apply_filters( 'honest_analytics_pro_url', self::HOME );
	}

	/**
	 * What each of these reports would tell somebody.
	 *
	 * Built at call time rather than held in a constant, because every string
	 * in it is translated. `shows` lists what the real report actually contains
	 * - it is a description of software that exists, not a feature list written
	 * to sound impressive, and it should be corrected whenever the real screen
	 * changes.
	 *
	 * @return array<string,array{title:string,lede:string,shows:string[],note:string}>
	 */
	public static function features(): array {
		return [
			'campaigns'      => [
				'title' => __( 'Campaigns', 'honest-analytics' ),
				'lede'  => __( 'Which tagged links brought people to the site, and what they did once they arrived.', 'honest-analytics' ),
				'shows' => [
					__( 'Sessions, bounce rate and conversions for each campaign.', 'honest-analytics' ),
					__( 'Source, medium and campaign name read from utm_ tags and ad click IDs.', 'honest-analytics' ),
					__( 'A choice of last non-direct click, first click or linear attribution.', 'honest-analytics' ),
				],
				'note'  => __( 'Campaign parameters are stripped from stored paths in both editions, so this does not change what the Pages report says.', 'honest-analytics' ),
			],
			'locations'      => [
				'title' => __( 'Locations', 'honest-analytics' ),
				'lede'  => __( 'Which countries visits came from, resolved on your own server.', 'honest-analytics' ),
				'shows' => [
					__( 'Sessions and share of traffic for each country.', 'honest-analytics' ),
					__( 'Lookup against a local database you install yourself, so no geolocation service is ever called.', 'honest-analytics' ),
					__( 'Country and nothing finer. No city, no region.', 'honest-analytics' ),
				],
				'note'  => __( 'The address is resolved in memory during the request that discards it, in this edition and in the paid one alike.', 'honest-analytics' ),
			],
			'events'         => [
				'title' => __( 'Events', 'honest-analytics' ),
				'lede'  => __( 'What people did besides look at pages.', 'honest-analytics' ),
				'shows' => [
					__( 'Custom events, with a count and the number of sessions they happened in.', 'honest-analytics' ),
					__( 'Clicks on outbound links and on downloadable files.', 'honest-analytics' ),
					__( 'How far down each page people actually read.', 'honest-analytics' ),
					__( 'Form submissions from Contact Form 7, Gravity Forms, WPForms and Ninja Forms, and completed WooCommerce orders.', 'honest-analytics' ),
				],
				'note'  => __( 'Events ride the pageview beacon rather than adding requests of their own.', 'honest-analytics' ),
			],
			'goals'          => [
				'title' => __( 'Goals', 'honest-analytics' ),
				'lede'  => __( 'Whether the things you care about are actually happening.', 'honest-analytics' ),
				'shows' => [
					__( 'Conversions and conversion rate for each goal you define.', 'honest-analytics' ),
					__( 'Goals matched on a path, an event or a form submission.', 'honest-analytics' ),
					__( 'Which pages and which channels the conversions came from.', 'honest-analytics' ),
				],
				'note'  => '',
			],
			'funnels'        => [
				'title' => __( 'Funnels', 'honest-analytics' ),
				'lede'  => __( 'Where people give up on their way through the site.', 'honest-analytics' ),
				'shows' => [
					__( 'Entered, completed and completion rate for each funnel.', 'honest-analytics' ),
					__( 'Every step with its own drop-off, as a chart and as a table.', 'honest-analytics' ),
					__( 'Any ordered path you care to define between two points on the site.', 'honest-analytics' ),
				],
				'note'  => '',
			],
			'crawlers'       => [
				'title' => __( 'Crawlers', 'honest-analytics' ),
				'lede'  => __( 'What is hitting the site that is not a person.', 'honest-analytics' ),
				'shows' => [
					__( 'Requests per crawler, and which of them ask most often.', 'honest-analytics' ),
					__( 'Search engines, monitoring services, scrapers and AI crawlers, named individually.', 'honest-analytics' ),
				],
				'note'  => __( 'Crawler traffic is already excluded from every visitor figure in this edition. Only the breakdown of who they were is part of Pro.', 'honest-analytics' ),
			],
			'share'          => [
				'title' => __( 'Shared reports', 'honest-analytics' ),
				'lede'  => __( 'A link you can hand to a client, with no WordPress account and no login of any kind.', 'honest-analytics' ),
				'shows' => [
					__( 'A read-only overview at its own address, showing the figures for a rolling window you choose.', 'honest-analytics' ),
					__( 'Nothing else: no settings, no licence details, no other report, and no way back into this dashboard.', 'honest-analytics' ),
					__( 'Every link revocable on its own, with an optional expiry and a record of when it was last opened.', 'honest-analytics' ),
				],
				'note'  => __( 'If a link ever leaks, the worst it gives away is the aggregate traffic figures it was made to show.', 'honest-analytics' ),
			],
			'search-console' => [
				'title' => __( 'Search Console', 'honest-analytics' ),
				'lede'  => __( 'What people searched to find the site - the one report here whose figures come from Google rather than from this plugin.', 'honest-analytics' ),
				'shows' => [
					__( 'The search terms that brought people to each page, with clicks, impressions and average position.', 'honest-analytics' ),
					__( 'A daily sync that keeps the last week current, because Google keeps revising its own recent figures for a few days after the fact.', 'honest-analytics' ),
					__( 'Up to sixteen months of history, brought across once and kept from there.', 'honest-analytics' ),
				],
				'note'  => __( 'Search Console clicks are Google\'s own count, on Google\'s own rules - shown next to this plugin\'s figures, never added into them.', 'honest-analytics' ),
			],
		];
	}
}
