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
use HonestAnalytics\Admin\Screens\SetupScreen;
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
 * A build with no Pro code in it shows no trace of the Pro reports: no row, no
 * badge, no page. The stand-ins exist for one case only, which is a build that
 * *has* the code and no active licence - somebody whose licence lapsed gets a
 * page saying what the report contains rather than a locked door.
 *
 * This reverses ADR 57, which kept the rows in the free menu so that people
 * could find out the reports existed. The cost of removing them is real and was
 * argued for; the plugin directory's answer, on review of 0.9.2, was that a menu
 * of features a build does not have reads as locked functionality whatever the
 * page says. The free edition names the paid one in its readme and nowhere else.
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
	 * The stand-in each Pro report gets on a build whose licence has lapsed.
	 *
	 * Keyed by class name, which PHP resolves to a plain string at compile time
	 * without autoloading anything, so naming a class this build may not contain
	 * is safe here. The values are the keys in `LockedScreen::features()`.
	 *
	 * Consulted only when `Edition::hasPro()` - a free build has neither the
	 * screens nor `LockedScreen` itself, and shows nothing where they were.
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

		if ( ! Edition::hasPro() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'menuStyles' ] );

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
			$screen = class_exists( $class ) ? new $class() : $this->standIn( $class );

			// A real screen this licence may not open becomes its own stand-in,
			// so a lapsed Pro gets a description rather than a 403.
			if ( null !== $screen && $screen->isPro() && ! $isPro ) {
				$screen = $this->standIn( $class );
			}

			// Nothing to show: either a build that does not carry this screen
			// and has no stand-in for it either, which is every Pro report in
			// the free build, or the licence screen, which never gets one.
			if ( null === $screen ) {
				continue;
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

		// The setup wizard has no place in the menu: it is a one-time flow
		// reached from the welcome banner or by URL, not a screen somebody
		// returns to. A `null` parent makes it routable without a row. It ships
		// in both editions, so it needs no edition check.
		$setup = new SetupScreen();
		$hook  = (string) add_submenu_page(
			'',
			$setup->title(),
			$setup->menuLabel(),
			$setup->capability(),
			$setup->slug(),
			[ $setup, 'renderPage' ]
		);
		$setup->bind( $hook );
	}

	/**
	 * The badge on a report this build has but this licence cannot open.
	 *
	 * The admin menu appears on every screen in WordPress, so this is registered
	 * against a handle with no file of its own and filled with
	 * `wp_add_inline_style()` rather than shipping a stylesheet for one word.
	 * It runs only on a build that carries the Pro reports and has no active
	 * licence: with a licence there is nothing marked, and in the free build
	 * there is no row to mark.
	 */
	public function menuStyles(): void {
		if ( Edition::isPro() ) {
			return;
		}

		$handle = 'honest-analytics-menu';

		wp_register_style( $handle, false, [], HONEST_ANALYTICS_VERSION );
		wp_enqueue_style( $handle );

		wp_add_inline_style(
			$handle,
			'#adminmenu .ha-menu-pro{display:inline-block;margin-left:6px;padding:0 5px;'
				. 'border:1px solid currentColor;border-radius:2px;opacity:.55;font-size:9px;'
				. 'font-weight:600;line-height:15px;text-transform:uppercase;'
				. 'letter-spacing:.04em;vertical-align:1px}'
				. '#adminmenu li.current .ha-menu-pro,'
				. '#adminmenu a:hover .ha-menu-pro{opacity:.8}'
		);
	}

	/**
	 * The page a Pro report leaves behind, if it leaves one.
	 *
	 * Nothing at all in a build with no Pro code: there is no row to explain,
	 * and `LockedScreen` is not in that build either, which is why the
	 * `hasPro()` check comes before the class is named rather than after.
	 *
	 * @param string $class The screen class that is not available here.
	 */
	private function standIn( string $class ): ?LockedScreen {
		if ( ! Edition::hasPro() ) {
			return null;
		}

		$key = self::STAND_INS[ $class ] ?? '';

		return '' !== $key ? LockedScreen::for( $key ) : null;
	}
}
