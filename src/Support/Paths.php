<?php
/**
 * Filesystem locations.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the spool and the geo database live.
 *
 * The uploads directory rather than wp-content, because uploads is the one
 * directory WordPress guarantees is writable - media requires it - whereas
 * wp-content is read-only on several managed hosts.
 */
final class Paths {

	/**
	 * The plugin's private directory inside uploads.
	 *
	 * @param bool $create Whether to create it if missing.
	 */
	public static function baseDir( bool $create = false ): string {
		$override = defined( 'HONEST_ANALYTICS_DATA_DIR' ) ? (string) constant( 'HONEST_ANALYTICS_DATA_DIR' ) : '';

		if ( '' === $override ) {
			$uploads  = $create ? wp_upload_dir() : wp_get_upload_dir();
			$basedir  = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
			$override = $basedir . '/honest-analytics';
		}

		/**
		 * Filters the directory Honest Analytics writes its spool and geo data to.
		 *
		 * @param string $dir Absolute path, without a trailing slash.
		 */
		$dir = (string) apply_filters( 'honest_analytics_data_dir', untrailingslashit( $override ) );

		if ( $create ) {
			self::ensure( $dir );
		}

		return $dir;
	}

	/**
	 * Create a directory if it is missing, and make sure it is still guarded.
	 *
	 * The guard files used to be written only inside `! is_dir()`, so a
	 * directory that existed but had lost its `.htaccess` never got it back -
	 * an rsync that skipped dotfiles, a restore from a backup that did not keep
	 * them, a security plugin that tidied them away, a host that strips them.
	 * The spool would then be readable and nothing would say so. `protect()`
	 * only writes what is missing, so re-asserting costs three `file_exists()`
	 * calls on the paths that ask to create.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function ensure( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::protect( $dir );
	}

	/**
	 * The spool directory.
	 *
	 * @param bool $create Whether to create it if missing.
	 */
	public static function spoolDir( bool $create = false ): string {
		$dir = self::baseDir( $create ) . '/spool';

		if ( $create ) {
			self::ensure( $dir );
		}

		return $dir;
	}

	/**
	 * The public URL of the spool directory, if it has one.
	 *
	 * Only used to ask the server whether it will serve what is in there. If
	 * the data directory has been moved outside uploads with the constant or
	 * the filter, there is no URL and no exposure to check.
	 */
	public static function spoolUrl(): string {
		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		$baseurl = isset( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';
		$dir     = self::spoolDir();

		if ( '' === $basedir || '' === $baseurl || ! str_starts_with( $dir, $basedir ) ) {
			return '';
		}

		return $baseurl . substr( $dir, strlen( $basedir ) );
	}

	/**
	 * The live spool file.
	 *
	 * The filename carries an install-specific HMAC so that a server which
	 * cannot be told to deny the directory (nginx has no per-directory config
	 * file) still does not serve a guessable URL. The contents hold hashes,
	 * paths and user agents - never an address - so this is belt and braces.
	 */
	public static function spoolFile(): string {
		return self::spoolDir() . '/spool-' . self::secretSuffix() . '.ndjson';
	}

	/**
	 * Where dompdf caches the font subsets it builds while rendering a
	 * client-shareable report to PDF.
	 *
	 * @param bool $create Whether to create it if missing.
	 */
	public static function pdfCacheDir( bool $create = false ): string {
		$dir = self::baseDir( $create ) . '/pdf-cache';

		if ( $create ) {
			self::ensure( $dir );
		}

		return $dir;
	}

	/**
	 * The MaxMind-format geo database.
	 *
	 * The filename carries the same install-specific HMAC the spool file does,
	 * and for the same reason: `protect()` writes `.htaccess` and `web.config`,
	 * both of which nginx ignores, and nginx cannot be configured from PHP. At
	 * a fixed name this was a seventy-megabyte GeoLite2 or DB-IP build sitting
	 * at a URL anybody could guess - a redistribution of a database the site is
	 * licensed only to use, and a bandwidth sink. `Loopback::checkSpool()`
	 * probes the spool subdirectory only, so nothing warned about it either.
	 *
	 * A file already at the old name is renamed on first use rather than
	 * re-downloaded: it is seventy megabytes and there is nothing wrong with
	 * its contents.
	 */
	public static function geoDatabase(): string {
		$path = self::baseDir() . '/geo-' . self::secretSuffix( 'geo' ) . '.mmdb';

		self::migrateGeoDatabase( $path );

		/**
		 * Filters the path to the local geo database.
		 *
		 * @param string $path Absolute path to a .mmdb file.
		 */
		return (string) apply_filters( 'honest_analytics_geo_database', $path );
	}

	/**
	 * Move a database left at the old guessable name.
	 *
	 * @param string $target Where it should be now.
	 */
	private static function migrateGeoDatabase( string $target ): void {
		if ( is_file( $target ) ) {
			return;
		}

		$legacy = self::baseDir() . '/geo.mmdb';

		if ( ! is_file( $legacy ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		@rename( $legacy, $target );
	}

	/**
	 * A stable, unguessable suffix derived from the site's own salts.
	 *
	 * @param string $for What the suffix is for, so two files do not share one.
	 */
	public static function secretSuffix( string $for = 'spool' ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'honest-analytics';

		return substr( hash_hmac( 'sha256', $for, $salt ), 0, 16 );
	}

	/**
	 * Drop the deny-everything files into a directory.
	 *
	 * @param string $dir Directory.
	 */
	public static function protect( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = [
			'index.html' => '',
			'.htaccess'  => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		];

		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;

			if ( ! file_exists( $path ) ) {
				// A guard file. If the directory is not writable there is
				// nothing useful to do about it here, and the caller has
				// already decided the spool is usable.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				@file_put_contents( $path, $contents );
			}
		}
	}
}
