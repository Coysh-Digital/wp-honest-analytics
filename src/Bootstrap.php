<?php
/**
 * Hook wiring.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics;

use HonestAnalytics\Admin\MaintenanceHandler;
use HonestAnalytics\Admin\Menu;
use HonestAnalytics\Admin\Notices;
use HonestAnalytics\Admin\Posts\StatsMetaBox;
use HonestAnalytics\Admin\Posts\ViewsColumn;
use HonestAnalytics\Admin\ReportingApiScreen;
use HonestAnalytics\Admin\Widgets\LiveWidget;
use HonestAnalytics\Admin\Widgets\OverviewWidget;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Capture\RequestContext;
use HonestAnalytics\Capture\ShutdownRunner;
use HonestAnalytics\Cli\CommandRegistrar;
use HonestAnalytics\Distribution\Coexistence;
use HonestAnalytics\Export\ExportHandler;
use HonestAnalytics\Integrations\Hooks;
use HonestAnalytics\Integrations\OptimizerExclusions;
use HonestAnalytics\Privacy\PersonalData;
use HonestAnalytics\Privacy\PolicyContent;
use HonestAnalytics\Rest\NoContent;
use HonestAnalytics\Rest\PlainEndpoint;
use HonestAnalytics\Rest\RestUnlock;
use HonestAnalytics\Rest\Routes;
use HonestAnalytics\Schema\Installer;
use HonestAnalytics\Scheduling\Cron;
use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where every hook is attached.
 *
 * Split by context, because a front-end pageview has no business loading the
 * admin screens and an admin screen has no business running the capture path.
 */
final class Bootstrap {

	/**
	 * Whether boot has already run.
	 */
	private static bool $booted = false;

	/**
	 * Wire everything up.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		self::extensions();
		self::always();

		if ( is_admin() ) {
			self::admin();
		} else {
			self::front();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CommandRegistrar::register();
		}
	}

	/**
	 * Load an extension, if this package contains one.
	 *
	 * Everything below this line is the whole plugin. Anything a package adds
	 * on top of it - more reports, more capture, more scheduled work - arrives
	 * as a directory of its own that registers through the same hooks and
	 * filters any other code would use, and this is the only thing that knows
	 * such a directory might exist.
	 *
	 * A path rather than a class name, and included rather than called, so that
	 * a package without one names nothing that is not in it. The file registers
	 * its own hooks as it loads; there is no interface to implement and no
	 * contract beyond the ones the rest of the plugin already publishes.
	 *
	 * Before everything else, because a hook has to be attached before the
	 * thing that fires it runs.
	 */
	private static function extensions(): void {
		$extension = HONEST_ANALYTICS_DIR . 'src/Extensions/bootstrap.php';

		if ( is_file( $extension ) ) {
			require_once $extension;
		}
	}

	/**
	 * Hooks that belong in every context.
	 */
	private static function always(): void {
		Capabilities::register();
		Cron::register();
		Routes::register();
		NoContent::register();
		RestUnlock::register();
		Hooks::register();
		Coexistence::register();

		// Multisite: a site created after the plugin was network activated gets
		// its tables the moment it exists, rather than the first time somebody
		// opens its dashboard.
		add_action( 'wp_initialize_site', [ Installer::class, 'onNewSite' ], 100 );
		add_filter( 'wpmu_drop_tables', [ Installer::class, 'dropTablesOnSiteDelete' ] );

		// Importing is registered in every context: a migration keeps running
		// through cron long after the person who started it has closed the tab.
		Import\Runtime::register();

		// Everything cron would have done, for the many sites that have no cron
		// and no way to add one.
		Scheduling\Fallback::register();

		add_action( 'admin_init', [ PolicyContent::class, 'register' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ PersonalData::class, 'registerExporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ PersonalData::class, 'registerEraser' ] );
	}

	/**
	 * The capture path.
	 */
	private static function front(): void {
		if ( PlainEndpoint::isEnabled() ) {
			PlainEndpoint::register();
		}

		/**
		 * Fires while the capture path is being wired up.
		 *
		 * For anything that answers a front-end request and is not part of
		 * counting a page view.
		 */
		do_action( 'honest_analytics_register_front' );

		// Snapshotted at the last hook before a plugin can redirect and exit,
		// and while the main query is still the current one.
		add_action(
			'wp',
			static function (): void {
				RequestContext::capture( Plugin::instance()->server() );
			},
			PHP_INT_MAX
		);

		Plugin::instance()->injector()->register();

		OptimizerExclusions::register();
		ShutdownRunner::register();
	}

	/**
	 * The admin screens.
	 */
	private static function admin(): void {
		SettingsRepository::register();

		( new Menu() )->register();

		ReportingApiScreen::register();
		Notices::register();
		ExportHandler::register();

		/**
		 * Fires while the admin is being wired up.
		 *
		 * After the menu, so a screen added here can hang off it, and before
		 * the widgets and the post-list column.
		 */
		do_action( 'honest_analytics_register_admin' );

		MaintenanceHandler::register();
		OverviewWidget::register();
		LiveWidget::register();
		ViewsColumn::register();
		StatsMetaBox::register();
	}
}
