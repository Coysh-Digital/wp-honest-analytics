<?php
/**
 * Traffic channels.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Channels;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a visit arrived.
 *
 * A closed set stored inline as a smallint rather than as a dimension, because
 * it can never grow past what is listed here.
 */
enum Channel: int {

	case Direct   = 0;
	case Search   = 1;
	case Social   = 2;
	case Referral = 3;
	case Campaign = 4;
	case Internal = 5;

	/**
	 * The label shown in reports.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Direct   => __( 'Direct', 'honest-analytics' ),
			self::Search   => __( 'Search', 'honest-analytics' ),
			self::Social   => __( 'Social', 'honest-analytics' ),
			self::Referral => __( 'Referral', 'honest-analytics' ),
			self::Campaign => __( 'Campaign', 'honest-analytics' ),
			self::Internal => __( 'Internal', 'honest-analytics' ),
		};
	}

	/**
	 * A channel from a stored integer, never failing.
	 *
	 * @param int $value Stored value.
	 */
	public static function fromStored( int $value ): self {
		return self::tryFrom( $value ) ?? self::Direct;
	}
}
