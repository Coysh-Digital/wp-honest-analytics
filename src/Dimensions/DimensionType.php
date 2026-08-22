<?php
/**
 * Dimension types.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Dimensions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a dimension row describes.
 *
 * Stored as a smallint so the rollups carry two integers rather than a repeated
 * string: a path viewed a million times is one row here and an integer there.
 */
enum DimensionType: int {

	case Path            = 1;
	case ReferrerHost    = 2;
	case Browser         = 3;
	case Os              = 4;
	case EventName       = 5;
	case CampaignSource  = 6;
	case CampaignMedium  = 7;
	case CampaignName    = 8;
	case CampaignTerm    = 9;
	case CampaignContent = 10;
	case SearchTerm      = 11;
	case OutboundHost    = 12;
	case OutboundUrl     = 13;
	case Region          = 14;
	case Crawler         = 15;
	case SearchQuery     = 16;

	/**
	 * The reserved value everything past the daily cardinality cap folds into.
	 *
	 * Not a path, not a host, not a browser - an aggregate bucket. Nothing links
	 * to it and nothing drills into it, because there is nothing underneath.
	 */
	public const OTHER_VALUE = '__other__';

	/**
	 * A human label, for exports and empty states.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Path            => __( 'Page', 'honest-analytics' ),
			self::ReferrerHost    => __( 'Referring host', 'honest-analytics' ),
			self::Browser         => __( 'Browser', 'honest-analytics' ),
			self::Os              => __( 'Operating system', 'honest-analytics' ),
			self::EventName       => __( 'Event', 'honest-analytics' ),
			self::CampaignSource  => __( 'Campaign source', 'honest-analytics' ),
			self::CampaignMedium  => __( 'Campaign medium', 'honest-analytics' ),
			self::CampaignName    => __( 'Campaign', 'honest-analytics' ),
			self::CampaignTerm    => __( 'Campaign term', 'honest-analytics' ),
			self::CampaignContent => __( 'Campaign content', 'honest-analytics' ),
			self::SearchTerm      => __( 'Search term', 'honest-analytics' ),
			self::OutboundHost    => __( 'Outbound host', 'honest-analytics' ),
			self::OutboundUrl     => __( 'Outbound link', 'honest-analytics' ),
			self::Region          => __( 'Region', 'honest-analytics' ),
			self::Crawler         => __( 'Crawler', 'honest-analytics' ),
			self::SearchQuery     => __( 'Search query', 'honest-analytics' ),
		};
	}
}
