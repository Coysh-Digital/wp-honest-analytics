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
$honest_uninstall_site = static function (): bool {
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
		return false;
	}

	\HonestAnalytics\Schema\Schema::drop();

	// Every option and transient the plugin has ever written, found by prefix
	// rather than listed by name. A list here is only as complete as the last
	// person who remembered to extend it, and what it missed last time were
	// the Google OAuth tokens and client secret the import keeps - the one
	// thing somebody choosing to delete their data would least expect to find
	// still sitting in wp_options afterwards. Names are read first and handed
	// to delete_option(), so the object cache is kept honest too.
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall; there is no API for "every option with this prefix".
	$honest_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'honest_analytics_' ) . '%',
			$wpdb->esc_like( '_transient_honest_analytics_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_honest_analytics_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( (array) $honest_options as $option ) {
		$option = (string) $option;

		if ( str_starts_with( $option, '_transient_timeout_' ) ) {
			continue;
		}

		if ( str_starts_with( $option, '_transient_' ) ) {
			delete_transient( substr( $option, strlen( '_transient_' ) ) );

			continue;
		}

		delete_option( $option );
	}

	// Transients live in the object cache rather than the table where there
	// is one, so the ones with fixed names are cleared by name as well.
	foreach (
		[
			'honest_analytics_import_found_2',
			'honest_analytics_ga4_properties',
			'honest_analytics_gsc_sites',
			'honest_analytics_coexistence',
			'honest_analytics_maintenance_notice',
			'honest_analytics_geo_notice',
		] as $transient
	) {
		delete_transient( $transient );
	}

	foreach ( \HonestAnalytics\Scheduling\Cron::hooks() as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	\HonestAnalytics\Capabilities\Capabilities::revoke();

	// The spool holds hashes and paths, never an address - but it is ours, and
	// leaving files behind in somebody's uploads directory is untidy.
	$directory = \HonestAnalytics\Support\Paths::baseDir();

	if ( is_dir( $directory ) ) {
		foreach ( [ $directory . '/spool', $directory . '/pdf-cache', $directory ] as $honest_dir ) {
			if ( ! is_dir( $honest_dir ) ) {
				continue;
			}

			$honest_files = glob( $honest_dir . '/{,.}*', GLOB_BRACE );

			// glob() returns false on a directory it cannot read, and on a PHP
			// build without GLOB_BRACE. Casting that to an array gives
			// `[ false ]`, which is a TypeError the moment it reaches
			// is_file() under strict_types - the uninstall dies part way and
			// leaves behind the tables and options it was asked to remove,
			// having already revoked the capabilities that would let somebody
			// see the screen explaining why.
			foreach ( is_array( $honest_files ) ? $honest_files : [] as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $honest_dir );
		}
	}

	return true;
};

if ( is_multisite() ) {
	$honest_removed_something = false;

	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $honest_site_id ) {
		switch_to_blog( (int) $honest_site_id );

		// `finally`, because an uninstall that throws part way leaves every
		// query after it pointed at that site - so the loop would carry on and
		// drop the same site's tables again while the rest kept theirs.
		try {
			$honest_removed_something = $honest_uninstall_site() || $honest_removed_something;
		} finally {
			restore_current_blog();
		}
	}

	// Only when at least one site actually asked for its data to go. The
	// licence is network-wide and one purchase covers every site, so on a
	// network where every administrator chose to keep their history, deleting
	// it would throw away the record of a licence that was bought and paid for
	// while destroying nothing else - the one combination where the network
	// options are the only casualty.
	//
	// Literals, not class constants: the free build ships without the
	// licence layer, and uninstall must work in both editions.
	if ( $honest_removed_something ) {
		delete_site_option( 'honest_analytics_licence_status' );
		delete_site_option( 'honest_analytics_licence_retry' );
	}
} else {
	$honest_uninstall_site();
}

// User-level preferences are network-wide, so they are removed once.
delete_metadata( 'user', 0, \HonestAnalytics\Admin\Widgets\OverviewWidget::USER_META, '', true );
