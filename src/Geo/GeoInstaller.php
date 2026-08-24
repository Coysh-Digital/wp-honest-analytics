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
	 * The most this will download or read from disk.
	 *
	 * Comfortably above a GeoLite2 or DB-IP City build and far below anything
	 * that would exhaust a PHP memory limit, because the file is held whole
	 * while its format is checked.
	 */
	private const MAX_DOWNLOAD_BYTES = 320000000;

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

		// Checked before reading, not after: the point of the ceiling is not to
		// hold the file in memory in the first place.
		$size = filesize( $path );

		if ( false !== $size && $size > self::MAX_DOWNLOAD_BYTES ) {
			return __( 'That file is far larger than any geo database, so it has not been read.', 'honest-analytics' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path, false, null, 0, self::MAX_DOWNLOAD_BYTES );

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

		// `wp_safe_remote_get`, not `wp_remote_get`: the safe variant refuses
		// private and loopback ranges, so an administrator cannot be talked
		// into using this screen to reach something inside their own network.
		// The capability check upstream establishes who is asking, not what
		// they were told to type.
		//
		// The size limit matters as much. Without it the whole body is held in
		// memory before the magic bytes are even looked at, and a URL that
		// answers with a few hundred megabytes is a white screen rather than
		// an error message. The largest legitimate database here is a GeoLite2
		// or DB-IP City build at well under 200 MB.
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 120,
				'headers'             => [ 'Accept' => 'application/octet-stream' ],
				'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
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
			//
			// The ceiling applies to the decompressed size too. Without it a
			// few megabytes on the wire can inflate to several gigabytes in
			// memory, and the format check below never gets a chance to say
			// no. Anything cut off here loses the metadata MaxMind keeps at
			// the end of the file, so it fails that check rather than being
			// installed short.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$decoded = @gzdecode( $contents, self::MAX_DOWNLOAD_BYTES );

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

		// size_format() answers false for anything it cannot read as a number,
		// which would put a bare `false` into a row of the diagnostics table.
		$size = size_format( $info['size'] );

		$status = [
			'Installed' => __( 'Yes', 'honest-analytics' ),
			'Path'      => $info['path'],
			'Type'      => '' !== $info['type'] ? $info['type'] : __( 'Unknown', 'honest-analytics' ),
			'Size'      => false !== $size ? $size : __( 'Unknown', 'honest-analytics' ),
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
