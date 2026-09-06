<?php
/**
 * The Analytics menu.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Admin\Screens\ContentScreen;
use HonestAnalytics\Admin\Screens\DashboardScreen;
use HonestAnalytics\Admin\Screens\DevicesScreen;
use HonestAnalytics\Admin\Screens\ImportScreen;
use HonestAnalytics\Admin\Screens\PagesScreen;
use HonestAnalytics\Admin\Screens\PrivacyScreen;
use HonestAnalytics\Admin\Screens\RealtimeScreen;
use HonestAnalytics\Admin\Screens\Screen;
use HonestAnalytics\Admin\Screens\SettingsScreen;
use HonestAnalytics\Admin\Screens\SetupScreen;
use HonestAnalytics\Admin\Screens\SourcesScreen;
use HonestAnalytics\Capabilities\Capabilities;

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
 * The screens are a list, and the list is a filter. Everything this package
 * contains is in SCREENS; anything a package adds on top of it arrives through
 * `honest_analytics_admin_screens` as an ordinary Screen, in whatever position
 * it asks for. Nothing here knows or asks what else might exist.
 */
final class Menu {

	public const SLUG = 'honest-analytics';

	/**
	 * The screens in this package, in the order the prototype puts them.
	 *
	 * Instantiated in `build()` and passed through a filter, so a package with
	 * more screens inserts them where they belong rather than appending them.
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
		PrivacyScreen::class,
		SettingsScreen::class,
		ImportScreen::class,
	];



	/**
	 * Attach the hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'build' ] );
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

		$screens = [];

		foreach ( self::SCREENS as $class ) {
			$screens[] = new $class();
		}

		/**
		 * Filters the screens in the Analytics menu.
		 *
		 * Instances rather than class names, so that a screen can be swapped for
		 * a different one that answers the same slug - which is what a package
		 * that cannot currently open its own reports does with them.
		 *
		 * @param Screen[] $screens Screens, in the order they appear.
		 */
		$screens = (array) apply_filters( 'honest_analytics_admin_screens', $screens );

		foreach ( $screens as $screen ) {
			if ( ! $screen instanceof Screen ) {
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
		// in every build, so it needs no check.
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
}
