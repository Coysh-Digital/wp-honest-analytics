<?php
/**
 * The salt command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Plugin;
use HonestAnalytics\Scheduling\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rotating secret behind every visitor identity.
 *
 * `rotate` is the command that demonstrates the central privacy claim rather
 * than asserting it: run it, reload the site, and yesterday's visitor is a new
 * visitor with no way - for anybody, including the site's owner - to connect
 * the two. There is no history table and no archived copy. The old value is
 * overwritten in place and is gone.
 */
final class SaltCommand {

	/**
	 * Destroys the current visitor salt and mints a new one.
	 *
	 * Everybody currently on the site becomes a new, unconnected visitor, and
	 * every hash produced under the old salt stops matching anything.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics salt rotate --yes
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function rotate( array $args, array $assocArgs = [] ): void {
		unset( $args );

		\WP_CLI::confirm(
			__( 'Rotating the salt makes today\'s visitors unrecognisable and cannot be undone. Continue?', 'honest-analytics' ),
			$assocArgs
		);

		$salts = Plugin::instance()->salts();

		$salts->rotate();
		$salts->flush();

		\WP_CLI::success( __( 'The salt has been replaced. The previous one no longer exists anywhere.', 'honest-analytics' ) );
		\WP_CLI::log( Health::describeSaltRotation() );
	}

	/**
	 * Shows when the salt was last replaced and when it is next due.
	 *
	 * Reads the stored row directly and does not mint a salt: "none yet" is a
	 * real state, and a status command that quietly created one would be lying
	 * about the thing it was asked to report.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics salt status
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function status( array $args, array $assocArgs = [] ): void {
		unset( $args, $assocArgs );

		\WP_CLI::log( Health::describeSaltRotation() );
	}
}
