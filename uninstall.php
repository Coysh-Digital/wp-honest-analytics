<?php
/**
 * Uninstall.
 *
 * Deleting the plugin leaves the tables alone unless somebody has explicitly
 * asked otherwise. The rollups cannot be rebuilt from anything else - no raw
 * hit data is kept, so there is nothing to replay - which makes destroying them
 * a decision rather than a default.
 *
 * With "Keep data on uninstall" switched off, this removes the lot: tables,
 * options, capabilities, cron events, spool and user preferences. The Privacy
 * screen says which way the setting stands before it ever comes to this.
 *
 * Deactivating never deletes anything either way. Somebody switching a plugin
 * off for ten minutes has not asked to lose two years of history.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// WordPress includes this file on its own, without loading the plugin, so the
// constants the autoloader relies on are not defined yet.
if ( ! defined( 'HONEST_ANALYTICS_DIR' ) ) {
	define( 'HONEST_ANALYTICS_DIR', plugin_dir_path( __FILE__ ) );
}

if ( is_file( HONEST_ANALYTICS_DIR . 'vendor/autoload.php' ) ) {
	require_once HONEST_ANALYTICS_DIR . 'vendor/autoload.php';
} else {
	require_once HONEST_ANALYTICS_DIR . 'src/Support/Autoloader.php';

	\HonestAnalytics\Support\Autoloader::register();
}

/**
 * Remove everything belonging to one site.
 */
$honest_uninstall_site = static function (): void {
	$settings = get_option( \HonestAnalytics\Settings\SettingsRepository::OPTION, [] );

	// The one setting read directly rather than through the repository: the
	// container is not booted during uninstall.
	//
	// Absent means keep. A missing option, a truncated one, a site whose
	// settings row never got written - every one of those should leave the
	// tables alone, because the only reading of "I could not find the setting"
	// that is safe is the one that does not destroy two years of history. The
	// data goes when somebody has explicitly said it should.
	$keep = ! is_array( $settings )
		|| ! array_key_exists( 'keepDataOnUninstall', $settings )
		|| ! empty( $settings['keepDataOnUninstall'] );

	if ( $keep ) {
		return;
	}

	\HonestAnalytics\Schema\Schema::drop();

	foreach (
		[
			\HonestAnalytics\Settings\SettingsRepository::OPTION,
			\HonestAnalytics\Schema\Installer::VERSION_OPTION,
			// A literal, not a class constant: the free build ships without the
			// licence layer, and uninstall must work in both editions.
			'honest_analytics_licence_status',
			'honest_analytics_licence_retry',
			'honest_analytics_last_drain',
			'honest_analytics_last_gc',
		] as $option
	) {
		delete_option( $option );
	}

	foreach (
		[
			\HonestAnalytics\Scheduling\Cron::DRAIN_HOOK,
			\HonestAnalytics\Scheduling\Cron::GC_HOOK,
			\HonestAnalytics\Scheduling\Cron::SALT_HOOK,
		] as $hook
	) {
		wp_clear_scheduled_hook( $hook );
	}

	\HonestAnalytics\Capabilities\Capabilities::revoke();

	// The spool holds hashes and paths, never an address - but it is ours, and
	// leaving files behind in somebody's uploads directory is untidy.
	$directory = \HonestAnalytics\Support\Paths::baseDir();

	if ( is_dir( $directory ) ) {
		$spool = $directory . '/spool';

		if ( is_dir( $spool ) ) {
			foreach ( (array) glob( $spool . '/{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $spool );
		}

		foreach ( (array) glob( $directory . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $directory );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $honest_site_id ) {
		switch_to_blog( (int) $honest_site_id );

		$honest_uninstall_site();

		restore_current_blog();
	}

	delete_site_option( 'honest_analytics_licence_status' );
} else {
	$honest_uninstall_site();
}

// User-level preferences are network-wide, so they are removed once.
delete_metadata( 'user', 0, \HonestAnalytics\Admin\Widgets\OverviewWidget::USER_META, '', true );
