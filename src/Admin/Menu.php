<?php
/**
 * The Analytics menu.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Admin\Screens\CampaignsScreen;
use HonestAnalytics\Admin\Screens\ContentScreen;
use HonestAnalytics\Admin\Screens\CrawlersScreen;
use HonestAnalytics\Admin\Screens\DashboardScreen;
use HonestAnalytics\Admin\Screens\DevicesScreen;
use HonestAnalytics\Admin\Screens\EventsScreen;
use HonestAnalytics\Admin\Screens\FunnelsScreen;
use HonestAnalytics\Admin\Screens\GoalsScreen;
use HonestAnalytics\Admin\Screens\ImportScreen;
use HonestAnalytics\Admin\Screens\LicenceScreen;
use HonestAnalytics\Admin\Screens\LockedScreen;
use HonestAnalytics\Admin\Screens\LocationsScreen;
use HonestAnalytics\Admin\Screens\PagesScreen;
use HonestAnalytics\Admin\Screens\PrivacyScreen;
use HonestAnalytics\Admin\Screens\RealtimeScreen;
use HonestAnalytics\Admin\Screens\SearchConsoleScreen;
use HonestAnalytics\Admin\Screens\SettingsScreen;
use HonestAnalytics\Admin\Screens\ShareScreen;
use HonestAnalytics\Admin\Screens\SourcesScreen;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Licensing\LicenceProviderInterface;
use HonestAnalytics\Licensing\LicenceService;
use HonestAnalytics\Licensing\StripeProvider;
use HonestAnalytics\Licensing\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One top-level menu, with the screens beneath it.
 *
 * The WordPress admin menu *is* the navigation. There is no sidebar drawn
 * inside the plugin's own pages, because a second navigation that looks like
 * the first one is how a plugin starts feeling like an application bolted onto
 * a site rather than a part of it.
 *
 * In Lite the Pro reports keep their places in the menu, marked, and lead to a
 * page describing what they contain. They are not hidden features: the free
 * build does not carry that code at all, so the page is a description of
 * software somebody does not have rather than a lock on software they do.
 * Leaving the rows out entirely was the earlier arrangement, and it left people
 * unable to find out the reports existed at all.
 */
final class Menu {

	public const SLUG = 'honest-analytics';

	/**
	 * Every screen, in the order the prototype puts them.
	 *
	 * Class names, not instances. The free build is packaged with the Pro
	 * screens removed, so the list has to survive naming a class that is not
	 * there - `class_exists()` in `build()` is what makes that a skipped row
	 * rather than a white screen.
	 *
	 * @var class-string[]
	 */
	private const SCREENS = [
		DashboardScreen::class,
		RealtimeScreen::class,
		PagesScreen::class,
		ContentScreen::class,
		SourcesScreen::class,
		DevicesScreen::class,
		CampaignsScreen::class,
		LocationsScreen::class,
		EventsScreen::class,
		GoalsScreen::class,
		FunnelsScreen::class,
		CrawlersScreen::class,
		ShareScreen::class,
		SearchConsoleScreen::class,
		PrivacyScreen::class,
		SettingsScreen::class,
		ImportScreen::class,
		LicenceScreen::class,
	];

	/**
	 * The stand-in each stripped report gets in the free build.
	 *
	 * Keyed by class name, which PHP resolves to a plain string at compile time
	 * without autoloading anything, so naming a class the free build does not
	 * contain is safe here. The values are the keys in
	 * `LockedScreen::features()`.
	 *
	 * @var array<class-string,string>
	 */
	private const STAND_INS = [
		CampaignsScreen::class     => 'campaigns',
		LocationsScreen::class     => 'locations',
		EventsScreen::class        => 'events',
		GoalsScreen::class         => 'goals',
		FunnelsScreen::class       => 'funnels',
		CrawlersScreen::class      => 'crawlers',
		ShareScreen::class         => 'share',
		SearchConsoleScreen::class => 'search-console',
	];


	/**
	 * Attach the hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'build' ] );
		add_action( 'admin_head', [ $this, 'menuStyles' ] );

		if ( ! Edition::hasPro() ) {
			return;
		}

		if ( ! class_exists( Updates::class ) ) {
			return;
		}

		// The default answer to `honest_analytics_licence_provider`, so a Pro
		// build talks to the real licence API out of the box. Still just a
		// filter: anything registered after this one - a test, a self-hosted
		// build with no commerce provider at all - wins in the usual way.
		add_filter( 'honest_analytics_licence_provider', static fn (): LicenceProviderInterface => new StripeProvider() );

		// Where the Licence screen sends "Receipts and licence management" -
		// there is no account area on this site to build, the provider
		// already has one.
		add_filter( 'honest_analytics_portal_url', static fn (): string => 'https://pro.honest-analytics.com/account' );

		Updates::register();

		// On `shutdown`, so that a site whose licence has not been checked for
		// a fortnight renders its admin page first and asks afterwards. The
		// check is throttled and nothing expires, so this is housekeeping
		// rather than anything a page load should wait for.
		add_action( 'shutdown', [ LicenceService::class, 'maybeRevalidate' ] );
	}

	/**
	 * Build the menu.
	 */
	public function build(): void {
		$dashboard = new DashboardScreen();

		$hook = (string) add_menu_page(
			__( 'Analytics', 'honest-analytics' ),
			__( 'Analytics', 'honest-analytics' ),
			Capabilities::VIEW,
			self::SLUG,
			[ $dashboard, 'renderPage' ],
			'dashicons-chart-bar',
			26
		);

		$dashboard->bind( $hook );

		$isPro = Edition::isPro();

		foreach ( self::SCREENS as $class ) {
			// Named rather than instantiated, and checked before anything is
			// constructed. The free build does not contain the Pro screen
			// classes at all, so `new CampaignsScreen()` followed by an edition
			// check is a fatal on wordpress.org and a passing test here.
			$missing = ! class_exists( $class );
			$screen  = $missing ? null : new $class();

			if ( $missing || ( $screen->isPro() && ! $isPro ) ) {
				$screen = $this->standIn( $class );

				// Something stripped that has no stand-in, which is the licence
				// screen and anything added later without one.
				if ( null === $screen ) {
					continue;
				}
			}

			// A build without the Pro code has nothing to unlock, so it has no
			// licence screen either. Offering a key field in the free edition
			// would be an advertisement dressed as a setting.
			if ( LicenceScreen::class === $class && ! Edition::hasPro() ) {
				continue;
			}

			// The dashboard is already bound to the top-level hook; it only needs
			// its place in the submenu.
			if ( $screen->slug() === self::SLUG ) {
				add_submenu_page(
					self::SLUG,
					$screen->title(),
					$screen->menuLabel(),
					$screen->capability(),
					$screen->slug()
				);

				continue;
			}

			$screen->register( self::SLUG );
		}
	}

	/**
	 * The badge on the marked menu items.
	 *
	 * Eight lines, printed inline, because the admin menu is on every screen in
	 * WordPress and enqueueing a stylesheet everywhere to letter one word would
	 * be a poor trade. Nothing is printed at all on a site that has Pro, since
	 * there is nothing marked.
	 */
	public function menuStyles(): void {
		if ( Edition::isPro() ) {
			return;
		}

		echo '<style id="honest-analytics-menu">
			#adminmenu .ha-menu-pro {
				display: inline-block;
				margin-left: 6px;
				padding: 0 5px;
				border: 1px solid currentColor;
				border-radius: 2px;
				opacity: .55;
				font-size: 9px;
				font-weight: 600;
				line-height: 15px;
				text-transform: uppercase;
				letter-spacing: .04em;
				vertical-align: 1px;
			}
			#adminmenu li.current .ha-menu-pro,
			#adminmenu a:hover .ha-menu-pro { opacity: .8; }
		</style>';
	}

	/**
	 * The page a stripped report leaves behind, if it leaves one.
	 *
	 * @param string $class The screen class that is not in this build.
	 */
	private function standIn( string $class ): ?LockedScreen {
		$key = self::STAND_INS[ $class ] ?? '';

		return '' !== $key ? LockedScreen::for( $key ) : null;
	}
}
