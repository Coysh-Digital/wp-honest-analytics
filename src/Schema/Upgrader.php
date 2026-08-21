<?php
/**
 * Lazy schema upgrades.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings a site's schema up to date whenever something is about to use it.
 *
 * Activation is not a reliable moment on multisite, and a plugin updated by
 * FTP or by a deploy never runs one at all. So the check is cheap - an option
 * read against a constant - and sits in front of the admin, the drain and every
 * CLI command. dbDelta is additive, so running it again on a current schema is
 * a no-op rather than a risk.
 */
final class Upgrader {

	/**
	 * Whether an upgrade has already run this request.
	 */
	private static bool $ran = false;

	/**
	 * Upgrade if the stored version is behind.
	 */
	public static function maybeUpgrade(): void {
		if ( self::$ran ) {
			return;
		}

		self::$ran = true;

		$stored = (int) get_option( Installer::VERSION_OPTION, 0 );

		if ( Schema::VERSION === $stored ) {
			return;
		}

		try {
			Installer::installSite();
		} catch ( \Throwable $e ) {
			Log::error( 'Schema upgrade failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether the schema is installed and current.
	 */
	public static function isCurrent(): bool {
		return (int) get_option( Installer::VERSION_OPTION, 0 ) === Schema::VERSION;
	}

	/**
	 * Reset the guard. For tests.
	 */
	public static function reset(): void {
		self::$ran = false;
	}
}
