<?php
/**
 * Whether anything in this build records more than a page view.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One question, asked in one place, by everything on the capture path.
 *
 * An event, an outbound click, a download or a scroll depth is only worth
 * accepting if something is going to write it down, and this is where the
 * capture path asks. The default is false: a package that records nothing
 * beyond page views leaves it that way and the beacon refuses interactions at
 * the door rather than accepting them and dropping them later.
 *
 * The setting is consulted first, so switching events off switches them off
 * wherever the writing happens.
 */
final class Interactions {

	/**
	 * Whether an interaction sent now would be recorded.
	 *
	 * @param Settings $settings The settings in force.
	 */
	public static function recorded( Settings $settings ): bool {
		if ( ! $settings->enableEvents ) {
			return false;
		}

		/**
		 * Filters whether anything records interactions in this build.
		 *
		 * False here means the beacon stops accepting them at the door rather
		 * than accepting them and dropping them later.
		 *
		 * @param bool     $recorded Whether interactions are written down.
		 * @param Settings $settings The settings in force.
		 */
		return (bool) apply_filters( 'honest_analytics_records_interactions', false, $settings );
	}
}
