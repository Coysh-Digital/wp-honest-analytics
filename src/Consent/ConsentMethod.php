<?php
/**
 * How consent was expressed.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a consent decision came from.
 *
 * Recorded alongside the decision because "they agreed" and "their consent
 * platform said they agreed" are different pieces of evidence.
 */
enum ConsentMethod: string {

	case JsApi         = 'js';
	case CmpAdapter    = 'cmp';
	case CmpCookie     = 'cookie';
	case ServerEvent   = 'server';
	case PrivacySignal = 'signal';

	/**
	 * The label shown in exports.
	 */
	public function label(): string {
		return match ( $this ) {
			self::JsApi         => __( 'JavaScript API', 'honest-analytics' ),
			self::CmpAdapter    => __( 'Consent platform', 'honest-analytics' ),
			self::CmpCookie     => __( 'Consent platform cookie', 'honest-analytics' ),
			self::ServerEvent   => __( 'Server-side', 'honest-analytics' ),
			self::PrivacySignal => __( 'Browser privacy signal', 'honest-analytics' ),
		};
	}

	/**
	 * A method from a stored string, never failing.
	 *
	 * @param string $value Stored value.
	 */
	public static function fromStored( string $value ): self {
		return self::tryFrom( $value ) ?? self::JsApi;
	}
}
