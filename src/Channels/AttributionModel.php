<?php
/**
 * Attribution models.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Channels;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a session with more than one campaign touch divides the credit.
 *
 * With a single touch every model agrees, which is most sessions.
 */
enum AttributionModel: string {

	case LastClick  = 'last-click';
	case FirstClick = 'first-click';
	case Linear     = 'linear';

	/**
	 * The label shown in reports.
	 */
	public function label(): string {
		return match ( $this ) {
			self::LastClick  => __( 'Last non-direct click', 'honest-analytics' ),
			self::FirstClick => __( 'First click', 'honest-analytics' ),
			self::Linear     => __( 'Linear', 'honest-analytics' ),
		};
	}

	/**
	 * The share of one session each touch receives.
	 *
	 * The weights always sum to exactly one, so a session touched by three
	 * campaigns is still one session however it is divided. That is why the
	 * campaign counters are decimals: splitting a session is honest, inventing
	 * two of them is not.
	 *
	 * @param int $touches Number of campaign touches.
	 *
	 * @return float[]
	 */
	public function weights( int $touches ): array {
		if ( $touches < 1 ) {
			return [];
		}

		return match ( $this ) {
			self::FirstClick => array_merge( [ 1.0 ], array_fill( 0, $touches - 1, 0.0 ) ),
			self::LastClick  => array_merge( array_fill( 0, $touches - 1, 0.0 ), [ 1.0 ] ),
			self::Linear     => array_fill( 0, $touches, (float) ( 1 / $touches ) ),
		};
	}

	/**
	 * A model from a stored string, never failing.
	 *
	 * @param string $value Stored value.
	 */
	public static function fromStored( string $value ): self {
		return self::tryFrom( $value ) ?? self::LastClick;
	}
}
