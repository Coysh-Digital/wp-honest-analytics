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
use HonestAnalytics\Admin\Widgets\LiveWidget;
use HonestAnalytics\Admin\Widgets\OverviewWidget;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Capture\RequestContext;
use HonestAnalytics\Capture\ShutdownRunner;
use HonestAnalytics\Cli\CommandRegistrar;
use HonestAnalytics\Distribution\Coexistence;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Export\ExportHandler;
use HonestAnalytics\Geo\GeoHandler;
use HonestAnalytics\Integrations\Hooks;
use HonestAnalytics\Integrations\OptimizerExclusions;
use HonestAnalytics\Privacy\PersonalData;
use HonestAnalytics\Privacy\PolicyContent;
use HonestAnalytics\Rest\NoContent;
use HonestAnalytics\Rest\PlainEndpoint;
use HonestAnalytics\Rest\ReportEndpoint;
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
	 * Hooks that belong in every context.
	 */
	private static function always(): void {
		load_plugin_textdomain( 'honest-analytics', false, dirname( HONEST_ANALYTICS_BASENAME ) . '/languages' );

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

		// Stripped from Lite along with the rest of src/Sharing/, and inert on a
		// Pro build with no active licence: a link nobody can currently create
		// or manage should not go on quietly answering requests.
		if ( Edition::isPro() && class_exists( ReportEndpoint::class ) ) {
			ReportEndpoint::register();
		}

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

		Notices::register();
		ExportHandler::register();
		GeoHandler::register();
		MaintenanceHandler::register();
		OverviewWidget::register();
		LiveWidget::register();
		ViewsColumn::register();
		StatsMetaBox::register();
	}
}
