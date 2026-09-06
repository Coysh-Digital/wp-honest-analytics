<?php
/**
 * Country lookup contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a visit came from, when the site can answer that at all.
 *
 * The seam between the capture path and a feature the free build does not
 * carry. `CaptureService` and `CollectController` each hold one of these as a
 * typed constructor dependency and call it on every hit, and the Settings
 * screen and the health check ask the container for one with no edition test
 * in front of them - so this cannot be a class that might be absent. A build
 * without the lookup gets {@see NoGeoLookup}, which answers "no database" to
 * everything, and nothing else changes.
 *
 * Every type named here survives into every build. That is the rule that keeps
 * a seam a seam: an interface that had to name a MaxMind reader, or anything
 * else that strips, would only move the problem one file along.
 */
interface GeoLookupInterface {

	/**
	 * Whether lookups are switched on and a database is actually installed.
	 */
	public function isAvailable(): bool;

	/**
	 * Resolve an address to a country and region.
	 *
	 * @param string $ip Client address. Discarded with this frame.
	 *
	 * @return array{country:string,region:string}|null
	 */
	public function resolve( string $ip ): ?array;

	/**
	 * Facts about the installed database, for the settings and Locations screens.
	 *
	 * @return array{installed:bool,path:string,size:int,built:?int,type:string}
	 */
	public function databaseInfo(): array;

	/**
	 * The attribution notice the database licence requires, if any.
	 */
	public function attributionNotice(): string;
}
