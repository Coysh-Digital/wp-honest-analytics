<?php
/**
 * Installing the local geo database.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Geo;

use HonestAnalytics\Settings\SettingsRepository;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts a MaxMind-format database where the lookup can find it.
 *
 * Nothing downloads on its own, ever. Fetching a third-party database is an
 * operator's decision with a licence attached, and a plugin that quietly
 * reaches out to somebody else's server on a schedule is exactly the behaviour
 * this one exists to avoid.
 */
final class GeoInstaller {

	/** The first two bytes of a gzip stream. */
	private const GZIP_MAGIC = "\x1f\x8b";

	/**
	 * Install from a local file.
	 *
	 * @param string $path Source path.
	 *
	 * @return true|string True, or an error message.
	 */
	public function installFromFile( string $path ): bool|string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return __( 'That file does not exist or cannot be read.', 'honest-analytics' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return __( 'That file could not be read.', 'honest-analytics' );
		}

		return $this->store( $contents );
	}

	/**
	 * Install from an HTTPS URL.
	 *
	 * @param string $url Source URL.
	 *
	 * @return true|string True, or an error message.
	 */
	public function installFromUrl( string $url ): bool|string {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return __( 'The download URL must use HTTPS.', 'honest-analytics' );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 120,
				'headers' => [ 'Accept' => 'application/octet-stream' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The download returned HTTP %d.', 'honest-analytics' ),
				(int) wp_remote_retrieve_response_code( $response )
			);
		}

		return $this->store( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Write the database into place, decompressing if needed.
	 *
	 * @param string $contents File contents.
	 *
	 * @return true|string True, or an error message.
	 */
	private function store( string $contents ): bool|string {
		if ( str_starts_with( $contents, self::GZIP_MAGIC ) ) {
			// gzdecode() warns as well as returning false on a truncated or
			// mislabelled file, which is exactly the case being handled below.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$decoded = @gzdecode( $contents );

			if ( false === $decoded ) {
				return __( 'That file looked gzipped but could not be decompressed.', 'honest-analytics' );
			}

			$contents = $decoded;
		}

		if ( ! str_contains( substr( $contents, -100000 ), "\xAB\xCD\xEFMaxMind.com" ) ) {
			return __( 'That does not look like a MaxMind-format database. DB-IP Lite and GeoLite2 both work; a CSV export does not.', 'honest-analytics' );
		}

		Paths::baseDir( true );

		$path = Paths::geoDatabase();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $contents ) ) {
			return __( 'The database could not be written. Check that the uploads directory is writable.', 'honest-analytics' );
		}

		return true;
	}

	/**
	 * What is currently installed.
	 *
	 * @return array<string,string>
	 */
	public function status(): array {
		$settings = SettingsRepository::get();
		$service  = new GeoService( $settings );
		$info     = $service->databaseInfo();

		if ( ! $info['installed'] ) {
			return [
				'Installed' => __( 'No', 'honest-analytics' ),
				'Path'      => $info['path'],
			];
		}

		$status = [
			'Installed' => __( 'Yes', 'honest-analytics' ),
			'Path'      => $info['path'],
			'Type'      => '' !== $info['type'] ? $info['type'] : __( 'Unknown', 'honest-analytics' ),
			'Size'      => size_format( $info['size'] ),
			'Built'     => null !== $info['built'] ? gmdate( 'Y-m-d', $info['built'] ) : __( 'Unknown', 'honest-analytics' ),
		];

		$attribution = $service->attributionNotice();

		if ( '' !== $attribution ) {
			$status['Attribution'] = $attribution;
		}

		if ( ! $settings->enableGeo ) {
			$status['Note'] = __( 'The database is installed but geography is switched off in the settings, so nothing is being recorded.', 'honest-analytics' );
		}

		return $status;
	}
}
