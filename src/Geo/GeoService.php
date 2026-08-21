<?php
/**
 * Country lookup from a local database.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Geo;

use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Paths;
use MaxMind\Db\Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a visit came from, resolved on this server and nowhere else.
 *
 * No lookup service is ever called. The address is a parameter to a local
 * database read inside the same request that throws it away, which is the only
 * arrangement compatible with never storing one.
 *
 * Country and region only. The city is present in the data and is deliberately
 * discarded: a city is a small enough area to start identifying people in, and
 * a country counter is the most granular thing this can honestly report.
 */
final class GeoService {

	private Settings $settings;

	/**
	 * The open reader, if the database is installed.
	 */
	private ?Reader $reader = null;

	/**
	 * Whether opening the reader has already been attempted.
	 */
	private bool $attempted = false;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether geo lookups are switched on and possible.
	 */
	public function isAvailable(): bool {
		return $this->settings->enableGeo
			&& Edition::isPro()
			&& class_exists( Reader::class )
			&& is_file( Paths::geoDatabase() );
	}

	/**
	 * Resolve an address to a country and region.
	 *
	 * @param string $ip Client address. Discarded with this frame.
	 *
	 * @return array{country:string,region:string}|null
	 */
	public function resolve( string $ip ): ?array {
		if ( '' === $ip || ! $this->isAvailable() ) {
			return null;
		}

		$reader = $this->reader();

		if ( null === $reader ) {
			return null;
		}

		try {
			$record = $reader->get( $ip );
		} catch ( \Throwable ) {
			// An unroutable or malformed address is ordinary, not exceptional.
			return null;
		}

		if ( ! is_array( $record ) ) {
			return null;
		}

		$country = self::extractCountry( $record );

		if ( null === $country ) {
			return null;
		}

		return [
			'country' => $country,
			'region'  => self::extractRegion( $record ) ?? '',
		];
	}

	/**
	 * Facts about the installed database, for the settings and Locations screens.
	 *
	 * @return array{installed:bool,path:string,size:int,built:?int,type:string}
	 */
	public function databaseInfo(): array {
		$path = Paths::geoDatabase();

		if ( ! is_file( $path ) ) {
			return [
				'installed' => false,
				'path'      => $path,
				'size'      => 0,
				'built'     => null,
				'type'      => '',
			];
		}

		$built = null;
		$type  = '';

		$reader = $this->reader();

		if ( null !== $reader ) {
			try {
				$metadata = $reader->metadata();
				$built    = (int) $metadata->buildEpoch;
				$type     = (string) $metadata->databaseType;
			} catch ( \Throwable ) {
				$built = null;
			}
		}

		return [
			'installed' => true,
			'path'      => $path,
			'size'      => (int) filesize( $path ),
			'built'     => $built,
			'type'      => $type,
		];
	}

	/**
	 * The attribution notice the database licence requires, if any.
	 */
	public function attributionNotice(): string {
		$info = $this->databaseInfo();

		if ( ! $info['installed'] ) {
			return '';
		}

		if ( str_contains( strtolower( $info['type'] ), 'dbip' ) ) {
			return __( 'IP geolocation by DB-IP, used under CC BY 4.0.', 'honest-analytics' );
		}

		if ( str_contains( strtolower( $info['type'] ), 'geolite' ) ) {
			return __( 'This product includes GeoLite2 data created by MaxMind.', 'honest-analytics' );
		}

		return '';
	}

	/**
	 * Open the database, once per request.
	 */
	private function reader(): ?Reader {
		if ( $this->attempted ) {
			return $this->reader;
		}

		$this->attempted = true;

		$path = Paths::geoDatabase();

		if ( ! class_exists( Reader::class ) || ! is_file( $path ) ) {
			return null;
		}

		try {
			$this->reader = new Reader( $path );
		} catch ( \Throwable $e ) {
			Log::warning( 'Could not open the geo database: ' . $e->getMessage() );
			$this->reader = null;
		}

		return $this->reader;
	}

	/**
	 * The ISO country code from a record.
	 *
	 * @param array<string,mixed> $record Database record.
	 */
	private static function extractCountry( array $record ): ?string {
		$code = $record['country']['iso_code'] ?? ( $record['registered_country']['iso_code'] ?? null );

		if ( ! is_string( $code ) || 2 !== strlen( $code ) ) {
			return null;
		}

		return strtoupper( $code );
	}

	/**
	 * The first subdivision name from a record.
	 *
	 * @param array<string,mixed> $record Database record.
	 */
	private static function extractRegion( array $record ): ?string {
		$name = $record['subdivisions'][0]['names']['en'] ?? null;

		return is_string( $name ) && '' !== $name ? $name : null;
	}
}
