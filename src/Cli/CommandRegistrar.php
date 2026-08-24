<?php
/**
 * WP-CLI command registration.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Schema\Upgrader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every command, in one place.
 *
 * The commands are not a convenience wrapper around the admin screens. Two of
 * them are the supported way to run this plugin properly: `drain` is what a real
 * cron job calls on any site busy enough for WP-Cron to be a liability, and `gc`
 * is what enforces the retention promises on a schedule somebody can audit.
 *
 * They are also the only interface that works when the admin will not load,
 * which is exactly when somebody most needs to ask what is going on.
 */
final class CommandRegistrar {

	/**
	 * Register the commands with WP-CLI.
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		// The other place a migration may run from. A terminal has nobody
		// waiting on it, and somebody who has just deployed by FTP and typed a
		// command is exactly the person who should not have to visit an admin
		// screen before the drain will do anything.
		//
		// Registered as a hook rather than called here: `register()` runs while
		// the plugin is still booting, and the schema is not something to touch
		// before WordPress has finished loading.
		add_action( 'init', [ Upgrader::class, 'maybeUpgrade' ], 5 );

		\WP_CLI::add_command( 'honest-analytics drain', DrainCommand::class );
		\WP_CLI::add_command( 'honest-analytics gc', GcCommand::class );
		\WP_CLI::add_command( 'honest-analytics info', InfoCommand::class );
		\WP_CLI::add_command( 'honest-analytics salt', SaltCommand::class );
		\WP_CLI::add_command( 'honest-analytics geo', GeoCommand::class );
		\WP_CLI::add_command( 'honest-analytics privacy', PrivacyCommand::class );
		\WP_CLI::add_command( 'honest-analytics report', ReportCommand::class );
		\WP_CLI::add_command( 'honest-analytics seed', SeedCommand::class );
	}
}
