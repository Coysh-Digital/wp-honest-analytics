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

		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect( $dir );
		}

		return $dir;
	}

	/**
	 * The spool directory.
	 *
	 * @param bool $create Whether to create it if missing.
	 */
	public static function spoolDir( bool $create = false ): string {
		$dir = self::baseDir( $create ) . '/spool';

		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect( $dir );
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

		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect( $dir );
		}

		return $dir;
	}

	/**
	 * The MaxMind-format geo database.
	 */
	public static function geoDatabase(): string {
		/**
		 * Filters the path to the local geo database.
		 *
		 * @param string $path Absolute path to a .mmdb file.
		 */
		return (string) apply_filters( 'honest_analytics_geo_database', self::baseDir() . '/geo.mmdb' );
	}

	/**
	 * A stable, unguessable suffix derived from the site's own salts.
	 */
	public static function secretSuffix(): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'honest-analytics';

		return substr( hash_hmac( 'sha256', 'spool', $salt ), 0, 16 );
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
