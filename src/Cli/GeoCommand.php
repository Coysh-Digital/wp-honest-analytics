<?php
/**
 * The geo database command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Geo\GeoInstaller;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The local country database.
 *
 * Country reporting is done here, on this server, against a file on disk.
 * Nothing is sent anywhere: no lookup service, no API key, no request carrying
 * a visitor's address to a third party. That is the whole reason the database
 * has to be installed rather than simply switched on, and this command is how.
 */
final class GeoCommand {

	/**
	 * Installs a MaxMind-format database from a file or a URL.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : A .mmdb file already on this server.
	 *
	 * [--url=<url>]
	 * : A URL to download it from. Must be one you are licensed to use.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics geo install --file=/tmp/GeoLite2-Country.mmdb
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function install( array $args, array $assocArgs = [] ): void {
		$source = $assocArgs['file'] ?? ( $args[0] ?? '' );
		$url    = $assocArgs['url'] ?? '';

		$installer = new GeoInstaller();

		if ( '' !== $url ) {
			$result = $installer->installFromUrl( (string) $url );
		} elseif ( '' !== $source ) {
			$result = $installer->installFromFile( (string) $source );
		} else {
			\WP_CLI::error( __( 'Give it something to install: --file=<path> or --url=<url>.', 'honest-analytics' ) );

			return;
		}

		if ( true !== $result ) {
			\WP_CLI::error( is_string( $result ) ? $result : __( 'The database could not be installed.', 'honest-analytics' ) );

			return;
		}

		\WP_CLI::success( __( 'Installed. Switch on country reporting in Settings to start using it.', 'honest-analytics' ) );

		$this->status( [], [] );
	}

	/**
	 * Shows what database is installed, if any.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics geo status
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function status( array $args, array $assocArgs = [] ): void {
		unset( $args, $assocArgs );

		$info = Plugin::instance()->geo()->databaseInfo();
		$rows = [];

		foreach ( $info as $field => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$rows[] = [
				'field' => (string) $field,
				'value' => is_scalar( $value ) ? (string) $value : '',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
	}
}
