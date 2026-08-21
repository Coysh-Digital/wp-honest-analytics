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
use HonestAnalytics\Admin\Screens\LocationsScreen;
use HonestAnalytics\Admin\Screens\PagesScreen;
use HonestAnalytics\Admin\Screens\PrivacyScreen;
use HonestAnalytics\Admin\Screens\RealtimeScreen;
use HonestAnalytics\Admin\Screens\SettingsScreen;
use HonestAnalytics\Admin\Screens\SourcesScreen;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Licensing\LicenceService;
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
 * Pro screens are not registered in Lite. Showing a menu item that leads to an
 * advertisement is worse than not showing it: the dashboard already says what
 * those reports would tell you, in one restrained sentence each.
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
		PrivacyScreen::class,
		SettingsScreen::class,
		ImportScreen::class,
		LicenceScreen::class,
	];


	/**
	 * Attach the hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'build' ] );

		if ( ! Edition::hasPro() ) {
			return;
		}

		if ( ! class_exists( Updates::class ) ) {
			return;
		}

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
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$screen = new $class();

			if ( $screen->isPro() && ! $isPro ) {
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
	}
}
