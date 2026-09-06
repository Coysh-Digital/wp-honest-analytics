<?php
/**
 * The country lookup a build without one has.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * No database, no lookup, no country - said in the shape everything expects.
 *
 * This is not a disabled GeoService. The free build does not contain one, and
 * the MaxMind reader it would need is not in that package either; this is what
 * the container hands out instead, so that the capture path can call the same
 * method on every hit without asking which build it is running in.
 *
 * `databaseInfo()` returns the same shape GeoService returns for a path that
 * holds no file, which is a state the paid build reaches too - a site that has
 * not installed a database yet. The Settings screen already draws that.
 *
 * Named No rather than Null because bin/build.sh asserts stripped class names
 * are absent from the regenerated classmap, and a NullGeoService would answer
 * to a search for GeoService.
 */
final class NoGeoLookup implements GeoLookupInterface {

	public function isAvailable(): bool {
		return false;
	}

	/**
	 * @param string $ip Client address, ignored.
	 *
	 * @return array{country:string,region:string}|null
	 */
	public function resolve( string $ip ): ?array {
		return null;
	}

	/**
	 * @return array{installed:bool,path:string,size:int,built:?int,type:string}
	 */
	public function databaseInfo(): array {
		return [
			'installed' => false,
			'path'      => '',
			'size'      => 0,
			'built'     => null,
			'type'      => '',
		];
	}

	public function attributionNotice(): string {
		return '';
	}
}
