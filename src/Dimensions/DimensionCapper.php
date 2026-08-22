<?php
/**
 * Daily cardinality cap.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Dimensions;

use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops one bad day from becoming a permanent table.
 *
 * A site can be hit with a million distinct query strings in an afternoon, and
 * without a ceiling every one of them becomes a dimension row and a rollup row.
 * Past the cap, values fold into a single `__other__` bucket: no views are
 * lost, they are simply not attributed to an individual value.
 *
 * The cap is first-N, not top-N, and that is a deliberate limitation rather
 * than an oversight. At the moment a value first appears there is no way to
 * know whether it will end the day popular, and holding the day's traffic in
 * memory to find out is exactly the cost this exists to avoid. A value already
 * admitted stays tracked for the rest of the day whatever arrives later.
 */
final class DimensionCapper {

	/**
	 * The types subject to the cap, and where their day's count is read from.
	 *
	 * Region is absent because the geo database bounds it. Channel is absent
	 * because it is a fixed enum stored inline, not a dimension at all.
	 * SearchQuery is absent for a third reason: it is not live-captured at all.
	 * Search Console data arrives as a complete, already-aggregated day, with
	 * click counts known upfront, so the Search Console rollup writer (Pro) can
	 * sort by clicks and keep the true top fifty per page rather than guessing
	 * with a first-N-per-arrival rule built for traffic nobody can see the end
	 * of yet.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function cappedTypes(): array {
		return [
			DimensionType::Path->value            => [ Tables::PAGES_ROLLUP, 'pathDimId' ],
			DimensionType::ReferrerHost->value    => [ Tables::SOURCES_ROLLUP, 'refHostDimId' ],
			DimensionType::Browser->value         => [ Tables::DEVICES_ROLLUP, 'browserDimId' ],
			DimensionType::Os->value              => [ Tables::DEVICES_ROLLUP, 'osDimId' ],
			DimensionType::CampaignSource->value  => [ Tables::CAMPAIGNS_ROLLUP, 'sourceDimId' ],
			DimensionType::CampaignMedium->value  => [ Tables::CAMPAIGNS_ROLLUP, 'mediumDimId' ],
			DimensionType::CampaignName->value    => [ Tables::CAMPAIGNS_ROLLUP, 'campaignDimId' ],
			DimensionType::CampaignTerm->value    => [ Tables::CAMPAIGNS_ROLLUP, 'termDimId' ],
			DimensionType::CampaignContent->value => [ Tables::CAMPAIGNS_ROLLUP, 'contentDimId' ],
			DimensionType::SearchTerm->value      => [ Tables::SEARCH_ROLLUP, 'termDimId' ],
			DimensionType::EventName->value       => [ Tables::EVENTS_ROLLUP, 'eventNameDimId' ],
			DimensionType::OutboundHost->value    => [ Tables::OUTBOUND_ROLLUP, 'targetHostDimId' ],
			DimensionType::OutboundUrl->value     => [ Tables::OUTBOUND_ROLLUP, 'targetDimId' ],
			DimensionType::Crawler->value         => [ Tables::CRAWLERS_ROLLUP, 'crawlerDimId' ],
		];
	}

	private DimensionsService $dimensions;
	private Settings $settings;

	/**
	 * Ids already counted today, keyed "site|date|type".
	 *
	 * @var array<string,array<int,true>>
	 */
	private array $admitted = [];

	/**
	 * The `__other__` id per type.
	 *
	 * @var array<int,int>
	 */
	private array $otherIds = [];

	/**
	 * @param DimensionsService $dimensions Dimensions service.
	 * @param Settings          $settings   Settings.
	 */
	public function __construct( DimensionsService $dimensions, Settings $settings ) {
		$this->dimensions = $dimensions;
		$this->settings   = $settings;
	}

	/**
	 * The dimension id to store for a value on a given day.
	 *
	 * @param int           $siteId Site ID.
	 * @param string        $date   Local date, Y-m-d.
	 * @param DimensionType $type   Dimension type.
	 * @param string        $value  Value.
	 */
	public function resolve( int $siteId, string $date, DimensionType $type, string $value ): int {
		if ( ! isset( self::cappedTypes()[ $type->value ] ) ) {
			return $this->dimensions->getOrCreateId( $type, $value );
		}

		if ( DimensionType::OTHER_VALUE === $value ) {
			return $this->otherId( $type );
		}

		$key = $siteId . '|' . $date . '|' . $type->value;

		if ( ! isset( $this->admitted[ $key ] ) ) {
			$this->admitted[ $key ] = $this->loadAdmitted( $siteId, $date, $type );
		}

		// Look up rather than create: creating first would bound the rollups
		// while leaving this table to grow with traffic.
		$dimId = $this->dimensions->getId( $type, $value );

		if ( null !== $dimId && isset( $this->admitted[ $key ][ $dimId ] ) ) {
			return $dimId;
		}

		if ( count( $this->admitted[ $key ] ) >= max( 10, $this->settings->dimensionCap ) ) {
			return $this->otherId( $type );
		}

		if ( null === $dimId ) {
			$dimId = $this->dimensions->getOrCreateId( $type, $value );
		}

		if ( $dimId > 0 ) {
			$this->admitted[ $key ][ $dimId ] = true;
		}

		return $dimId;
	}

	/**
	 * The id of the `__other__` value for a type.
	 *
	 * @param DimensionType $type Dimension type.
	 */
	public function otherId( DimensionType $type ): int {
		if ( ! isset( $this->otherIds[ $type->value ] ) ) {
			$this->otherIds[ $type->value ] = $this->dimensions->getOrCreateId( $type, DimensionType::OTHER_VALUE );
		}

		return $this->otherIds[ $type->value ];
	}

	/**
	 * The ids already recorded for a site, day and type.
	 *
	 * @param int           $siteId Site ID.
	 * @param string        $date   Local date.
	 * @param DimensionType $type   Dimension type.
	 *
	 * @return array<int,true>
	 */
	private function loadAdmitted( int $siteId, string $date, DimensionType $type ): array {
		global $wpdb;

		[ $table, $column ] = self::cappedTypes()[ $type->value ];

		$name   = Tables::name( $table );
		$column = preg_replace( '/[^A-Za-z0-9_]/', '', $column );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT `$column` FROM `$name` WHERE siteId = %d AND date = %s",
				$siteId,
				$date
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$admitted = [];

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$admitted[ $id ] = true;
			}
		}

		// The bucket itself must not eat one of the places, or the cap shrinks
		// by one the moment it engages.
		unset( $admitted[ $this->otherId( $type ) ] );

		return $admitted;
	}

	/**
	 * Forget what has been admitted. For long-running CLI work.
	 */
	public function flush(): void {
		$this->admitted = [];
	}
}
