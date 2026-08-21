<?php
/**
 * Device types.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Devices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three shapes of screen, plus "we could not tell".
 */
enum DeviceType: int {

	case Unknown = 0;
	case Desktop = 1;
	case Mobile  = 2;
	case Tablet  = 3;

	/**
	 * The label shown in reports.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Unknown => __( 'Unknown', 'honest-analytics' ),
			self::Desktop => __( 'Desktop', 'honest-analytics' ),
			self::Mobile  => __( 'Mobile', 'honest-analytics' ),
			self::Tablet  => __( 'Tablet', 'honest-analytics' ),
		};
	}

	/**
	 * A type from a stored integer, never failing.
	 *
	 * @param int $value Stored value.
	 */
	public static function fromStored( int $value ): self {
		return self::tryFrom( $value ) ?? self::Unknown;
	}
}
