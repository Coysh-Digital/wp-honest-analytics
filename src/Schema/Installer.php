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
use HonestAnalytics\Support\Db;
use HonestAnalytics\Support\Log;
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
	 * Where the first-run setup prompt remembers where it stands.
	 *
	 * Written once, on a genuinely fresh install (see `installSite()`), and read
	 * by the banner and the wizard. Its absence means an install that predates
	 * the wizard, which must never be greeted by it - so the value is only ever
	 * created here, never defaulted into existence by a reader.
	 */
	public const SETUP_OPTION = 'honest_analytics_setup';

	/** A fresh install that has not yet been through, or dismissed, setup. */
	public const SETUP_PENDING = 'pending';

	/** Setup was completed from the wizard. */
	public const SETUP_COMPLETE = 'complete';

	/** Setup was skipped, from the banner or the wizard. */
	public const SETUP_DISMISSED = 'dismissed';

	/**
	 * Sites handled per query when walking a network.
	 *
	 * A page size rather than a ceiling. On a large network the request may
	 * still time out part way, which is what the lazy `Upgrader` is for - but
	 * it will not stop at a round number and call the job done.
	 */
	private const SITES_PER_PAGE = 200;

	/**
	 * Plugin activation.
	 *
	 * @param bool $networkWide Whether the plugin was network activated.
	 */
	public static function activate( bool $networkWide = false ): void {
		if ( $networkWide && is_multisite() ) {
			self::eachSite( static fn () => self::installSite() );

			return;
		}

		self::installSite();
	}

	/**
	 * Run something on every site in the network, in pages.
	 *
	 * `'number' => 200` was a limit, not a page size, so site 201 and everything
	 * after it was simply skipped. For activation the lazy `Upgrader` covers
	 * that eventually; for deactivation nothing did, so those sites kept their
	 * scheduled events for ever - which `docs/uninstall.md` promises they will
	 * not.
	 *
	 * `try`/`finally` around the switch because the callback can throw, and an
	 * unbalanced `switch_to_blog()` leaves every query after it pointed at the
	 * wrong site's tables.
	 *
	 * @param callable():void $callback What to do on each site.
	 */
	private static function eachSite( callable $callback ): void {
		$offset = 0;

		while ( true ) {
			$sites = get_sites(
				[
					'fields'  => 'ids',
					'number'  => self::SITES_PER_PAGE,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				]
			);

			if ( [] === $sites ) {
				return;
			}

			foreach ( $sites as $siteId ) {
				switch_to_blog( (int) $siteId );

				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}

			$offset += self::SITES_PER_PAGE;
		}
	}

	/**
	 * Install on the current site.
	 */
	public static function installSite(): void {
		// Before dbDelta, because dbDelta adds missing indexes and never changes
		// an existing one. A unique key that has gained a column has to be
		// replaced by hand first, or dbDelta sees the old key, decides nothing
		// is missing, and leaves two rows that should be one quietly merging.
		$stored  = (int) get_option( self::VERSION_OPTION, 0 );
		$widened = self::widenBucketKeys( $stored );

		Schema::install();

		// The memo says which tables existed a moment ago, and dbDelta has just
		// changed that answer. A stale "no" here is a screen telling somebody
		// their tables are missing immediately after creating them.
		Schema::flushTableCache();

		if ( false === get_option( SettingsRepository::OPTION, false ) ) {
			add_option( SettingsRepository::OPTION, Settings::defaults(), '', true );

			// The one moment the setup wizard is offered: a site with no settings
			// of its own yet. Keyed to the absence of the settings option rather
			// than to the activation hook so that reactivating, or upgrading an
			// install configured long ago, never brings the welcome banner back.
			add_option( self::SETUP_OPTION, self::SETUP_PENDING, '', false );
		}

		Capabilities::grant();
		Cron::schedule();
		Paths::spoolDir( true );

		// Only when every step actually worked. Bumping regardless meant a
		// migration that failed was never attempted again: the version said it
		// was done, and whatever it had half-finished stayed half-finished for
		// the life of the install. Left behind, the version is retried on the
		// next run, and `Upgrader::isCurrent()` keeps the drain standing down
		// until it succeeds rather than writing against a schema it cannot use.
		// After dbDelta, because it writes into a table dbDelta has just made.
		$backfilled = self::backfillDailyUniques( $stored );

		if ( ! $widened || ! $backfilled ) {
			Log::error( 'The schema upgrade did not complete; the stored version is unchanged and it will be retried.' );

			return;
		}

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
			self::eachSite( static fn () => Cron::unschedule() );

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

		// `finally`, because installSite() can throw and an unbalanced
		// switch_to_blog() points every query after it at another site's tables.
		try {
			self::installSite();
		} finally {
			restore_current_blog();
		}
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
	 * Schema 7: build the site-wide daily uniques rows from what is already there.
	 *
	 * The reports read `honest_daily_uniques` for any question about a whole
	 * site, and until this version nothing wrote it - so without a backfill
	 * every historical day would report no visitors at all, which is a worse
	 * answer than the slow one it replaces.
	 *
	 * The sketches are merged here rather than recomputed, because they cannot
	 * be recomputed: the salts that produced the hashes are destroyed nightly
	 * (ADR 7), so the only record of who visited on 14 March is the sketch
	 * written on 14 March. Merging is exactly what the reports used to do on
	 * every render; this does it once.
	 *
	 * A day at a time, so the memory ceiling is one day's rows rather than the
	 * table's. Runs from cron or the CLI, never from a page load (ADR 58).
	 *
	 * @param int $stored The schema version already installed.
	 *
	 * @return bool Whether the tree is now as this step intends it.
	 */
	private static function backfillDailyUniques( int $stored ): bool {
		global $wpdb;

		// `$stored < 1` is a fresh install: there is nothing behind it to
		// backfill, and the drain writes these rows from now on.
		if ( $stored < 1 || $stored >= 7 ) {
			return true;
		}

		if ( ! Schema::tableExists( Tables::PAGES_ROLLUP ) || ! Schema::tableExists( Tables::DAILY_UNIQUES ) ) {
			return true;
		}

		try {
			( new DailyUniquesBackfill() )->run();

			return true;
		} catch ( \Throwable $e ) {
			Log::error( 'Could not build the daily uniques rows: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Schema 2: provenance joins the bucket key of every table an import writes.
	 *
	 * Native and imported figures for the same day are different rows, so that
	 * they can be told apart, counted separately, and removed separately. Until
	 * the key knows about `source` they would collide and add together, which is
	 * the one outcome nobody wants from an import.
	 *
	 * Every statement is checked, and every one is safe to run twice. It used to
	 * be neither, and the failure mode was permanent: `DROP INDEX bucket`
	 * followed by `ADD UNIQUE KEY bucket`, results unread, with the version
	 * bumped afterwards whatever happened. Two concurrent requests both entered,
	 * and in the window between the drop and the add a live upsert inserted
	 * duplicates instead of updating - after which the `ADD UNIQUE KEY` failed
	 * on "Duplicate entry" and the table was left **with no unique key at all**,
	 * for ever, because the version said the migration was done. From there
	 * every upsert appends a row and every report multiplies.
	 *
	 * @param int $stored The schema version already installed.
	 *
	 * @return bool Whether the tree is now as this step intends it.
	 */
	private static function widenBucketKeys( int $stored ): bool {
		if ( $stored < 1 || $stored >= 2 ) {
			return true;
		}

		$keys = [
			Tables::PAGES_ROLLUP        => 'siteId,date,hour,pathDimId,source',
			Tables::PAGE_SOURCES_ROLLUP => 'siteId,date,pathDimId,channel,refHostDimId,source',
			Tables::SESSIONS_ROLLUP     => 'siteId,date,hour,source',
			Tables::SOURCES_ROLLUP      => 'siteId,date,hour,channel,refHostDimId,source',
			Tables::DEVICES_ROLLUP      => 'siteId,date,browserDimId,browserMajor,osDimId,deviceType,source',
			Tables::GEO_ROLLUP          => 'siteId,date,countryCode,regionDimId,source',
		];

		$ok = true;

		foreach ( $keys as $table => $columns ) {
			if ( ! Schema::tableExists( $table ) ) {
				continue;
			}

			$ok = self::widenOneKey( $table, $columns ) && $ok;
		}

		return $ok;
	}

	/**
	 * Widen one table's bucket key, idempotently.
	 *
	 * @param string $table   Unprefixed table name.
	 * @param string $columns The key's new column list.
	 */
	private static function widenOneKey( string $table, string $columns ): bool {
		global $wpdb;

		$name = Tables::name( $table );

		try {
			if ( ! self::hasColumn( $table, 'source' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The identifier comes from Schema\Tables and the definition is a literal.
				Db::query( "ALTER TABLE `$name` ADD COLUMN `source` varchar(24) NOT NULL DEFAULT 'native'" );
			}

			if ( in_array( $table, [ Tables::PAGES_ROLLUP, Tables::SESSIONS_ROLLUP ], true ) && ! self::hasColumn( $table, 'importedUniques' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The identifier comes from Schema\Tables and the definition is a literal.
				Db::query( "ALTER TABLE `$name` ADD COLUMN `importedUniques` int NOT NULL DEFAULT 0" );
			}

			if ( self::keyColumns( $table, 'bucket' ) === $columns ) {
				return true;
			}

			// Widened rather than replaced: adding a column to a unique key can
			// only ever make it admit more rows, so this cannot fail on a
			// duplicate the way rebuilding it could. The old key is dropped only
			// once the new one is in place, so there is no instant at which the
			// table is without one and a concurrent upsert can append instead of
			// updating.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers come from Schema\Tables and a literal list; the column list is a literal.
			Db::query( "ALTER TABLE `$name` ADD UNIQUE KEY `bucket_wide` ($columns)" );
			Db::query( "ALTER TABLE `$name` DROP INDEX `bucket`" );
			Db::query( "ALTER TABLE `$name` ADD UNIQUE KEY `bucket` ($columns)" );
			Db::query( "ALTER TABLE `$name` DROP INDEX `bucket_wide`" );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

			return true;
		} catch ( \Throwable $e ) {
			Log::error( 'Could not widen the bucket key on ' . $table . ': ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Whether a table already carries a column.
	 *
	 * @param string $table  Unprefixed table name.
	 * @param string $column Column name.
	 */
	private static function hasColumn( string $table, string $column ): bool {
		global $wpdb;

		$name = Tables::name( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema reads have no core API and are deliberately uncached; the identifier comes from Schema\Tables and the value is a placeholder.
		return null !== $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$name` LIKE %s", $column ) );
	}

	/**
	 * The column list of a named key, comma separated, or an empty string.
	 *
	 * @param string $table Unprefixed table name.
	 * @param string $key   Key name.
	 */
	private static function keyColumns( string $table, string $key ): string {
		global $wpdb;

		$name = Tables::name( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema reads have no core API and are deliberately uncached; the identifier comes from Schema\Tables and the value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `$name` WHERE Key_name = %s", $key ), ARRAY_A );

		if ( ! is_array( $rows ) || [] === $rows ) {
			return '';
		}

		usort( $rows, static fn ( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );

		return implode( ',', array_map( static fn ( array $row ): string => (string) $row['Column_name'], $rows ) );
	}
}
