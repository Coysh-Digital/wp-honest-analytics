<?php
/**
 * Consent states.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a visitor has said.
 *
 * "Unknown" behaves exactly like a refusal: nothing durable is written until
 * somebody has affirmatively agreed.
 */
enum ConsentState: string {

	case Unknown = 'unknown';
	case Granted = 'granted';
	case Denied  = 'denied';

	/**
	 * The label shown on the Privacy screen.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Unknown => __( 'Not given', 'honest-analytics' ),
			self::Granted => __( 'Granted', 'honest-analytics' ),
			self::Denied  => __( 'Denied', 'honest-analytics' ),
		};
	}

	/**
	 * Whether this state unlocks the consented layer.
	 */
	public function isGranted(): bool {
		return self::Granted === $this;
	}
}
