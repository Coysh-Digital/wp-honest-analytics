<?php
/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * Composer's autoloader is used when `vendor/` is present. This fallback keeps
 * the plugin loadable from a plain git checkout, and keeps the third-party
 * libraries genuinely optional: every class that uses one degrades to a
 * built-in implementation rather than fataling.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PSR-4 autoloader for the HonestAnalytics namespace.
 */
final class Autoloader {

	private const PREFIX = 'HonestAnalytics\\';

	/**
	 * Register the autoloader.
	 *
	 * Prepending puts this ahead of Composer's classmap, which matters when
	 * that map describes a different build of this plugin: an authoritative
	 * map is the whole truth about the classes it lists, so a stale one both
	 * fails to find what is there and insists on including what is not. Asked
	 * first, this answers from the source tree that is actually on disk and the
	 * map is never consulted about our namespace at all.
	 *
	 * @param bool $prepend Whether to run before any autoloader already registered.
	 */
	public static function register( bool $prepend = false ): void {
		spl_autoload_register( [ self::class, 'load' ], true, $prepend );
	}

	/**
	 * Load a class file for the given fully-qualified class name.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = HONEST_ANALYTICS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
}
