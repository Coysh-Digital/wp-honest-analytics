<?php
/**
 * Activation, deactivation and per-site install.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Scheduling\Cron;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Settings\SettingsRepository;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Getting a plugin onto a site, once or a thousand times.
 *
 * Network activation loops the sites it can reach, and `Upgrader` finishes the
 * job lazily for the ones it cannot: on a large network the activation request
 * times out long before the loop ends, and a site whose tables were never
 * created is indistinguishable from a site that needs an upgrade. Treating both
 * the same way means there is only one code path that can be wrong.
 */
final class Installer {

	public const VERSION_OPTION = 'honest_analytics_db_version';

	/**
	 * Plugin activation.
	 *
	 * @param bool $networkWide Whether the plugin was network activated.
	 */
	public static function activate( bool $networkWide = false ): void {
		if ( $networkWide && is_multisite() ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => 200,
				]
			);

			foreach ( $sites as $siteId ) {
				switch_to_blog( (int) $siteId );
				self::installSite();
				restore_current_blog();
			}

			return;
		}

		self::installSite();
	}

	/**
	 * Install on the current site.
	 */
	public static function installSite(): void {
		// Before dbDelta, because dbDelta adds missing indexes and never changes
		// an existing one. A unique key that has gained a column has to be
		// replaced by hand first, or dbDelta sees the old key, decides nothing
		// is missing, and leaves two rows that should be one quietly merging.
		self::widenBucketKeys( (int) get_option( self::VERSION_OPTION, 0 ) );

		Schema::install();

		if ( false === get_option( SettingsRepository::OPTION, false ) ) {
			add_option( SettingsRepository::OPTION, Settings::defaults(), '', true );
		}

		Capabilities::grant();
		Cron::schedule();
		Paths::spoolDir( true );

		update_option( self::VERSION_OPTION, Schema::VERSION, true );
	}

	/**
	 * Plugin deactivation.
	 *
	 * Scheduled work stops; nothing is deleted. A plugin that throws away a
	 * site's history because somebody deactivated it for ten minutes has made a
	 * decision that was not theirs to make.
	 *
	 * @param bool $networkWide Whether the plugin was network activated.
	 */
	public static function deactivate( bool $networkWide = false ): void {
		if ( $networkWide && is_multisite() ) {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => 200,
				]
			);

			foreach ( $sites as $siteId ) {
				switch_to_blog( (int) $siteId );
				Cron::unschedule();
				restore_current_blog();
			}

			return;
		}

		Cron::unschedule();
	}

	/**
	 * Install on a site created after network activation.
	 *
	 * @param mixed $site WP_Site, or a site ID.
	 */
	public static function onNewSite( mixed $site ): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( HONEST_ANALYTICS_BASENAME ) ) {
			return;
		}

		$siteId = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;

		if ( $siteId <= 0 ) {
			return;
		}

		switch_to_blog( $siteId );
		self::installSite();
		restore_current_blog();
	}

	/**
	 * Add our tables to the list WordPress drops when a site is deleted.
	 *
	 * @param string[] $tables Table names.
	 *
	 * @return string[]
	 */
	public static function dropTablesOnSiteDelete( array $tables ): array {
		foreach ( Tables::all() as $table ) {
			$tables[] = Tables::name( $table );
		}

		return $tables;
	}
	/**
	 * Schema 2: provenance joins the bucket key of every table an import writes.
	 *
	 * Native and imported figures for the same day are different rows, so that
	 * they can be told apart, counted separately, and removed separately. Until
	 * the key knows about `source` they would collide and add together, which is
	 * the one outcome nobody wants from an import.
	 *
	 * @param int $stored The schema version already installed.
	 */
	private static function widenBucketKeys( int $stored ): void {
		if ( $stored < 1 || $stored >= 2 ) {
			return;
		}

		global $wpdb;

		$keys = [
			Tables::PAGES_ROLLUP        => 'siteId,date,hour,pathDimId,source',
			Tables::PAGE_SOURCES_ROLLUP => 'siteId,date,pathDimId,channel,refHostDimId,source',
			Tables::SESSIONS_ROLLUP     => 'siteId,date,hour,source',
			Tables::SOURCES_ROLLUP      => 'siteId,date,hour,channel,refHostDimId,source',
			Tables::DEVICES_ROLLUP      => 'siteId,date,browserDimId,browserMajor,osDimId,deviceType,source',
			Tables::GEO_ROLLUP          => 'siteId,date,countryCode,regionDimId,source',
		];

		foreach ( $keys as $table => $columns ) {
			$name = Tables::name( $table );

			if ( ! Schema::tableExists( $table ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and a literal list above.
			$wpdb->query( "ALTER TABLE `$name` ADD COLUMN `source` varchar(24) NOT NULL DEFAULT 'native'" );

			if ( in_array( $table, [ Tables::PAGES_ROLLUP, Tables::SESSIONS_ROLLUP ], true ) ) {
				$wpdb->query( "ALTER TABLE `$name` ADD COLUMN `importedUniques` int(11) NOT NULL DEFAULT 0" );
			}

			$wpdb->query( "ALTER TABLE `$name` DROP INDEX `bucket`" );
			$wpdb->query( "ALTER TABLE `$name` ADD UNIQUE KEY `bucket` ($columns)" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
