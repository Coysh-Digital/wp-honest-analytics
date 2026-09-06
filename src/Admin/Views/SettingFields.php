<?php
/**
 * The building blocks of the settings form.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Views;

use Closure;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The controls the settings form is built from, handed to whatever adds to it.
 *
 * The Settings screen builds its rows from six closures defined at the top of
 * its template. Anything adding a card to that form needs the same six, or its
 * rows come out looking like somebody else's - so they are collected here and
 * passed to `honest_analytics_settings_cards` rather than reimplemented.
 *
 * Closures rather than methods, because that is what the template already
 * holds and copying the markup to change the call syntax would be a lot of risk
 * for no gain. A template that receives one of these assigns them to variables
 * of the same names it would have used anyway.
 *
 * @psalm-immutable
 */
final class SettingFields {

	/**
	 * @param Settings $settings  The current settings.
	 * @param Closure  $overrides Where a value comes from, if not the database.
	 * @param Closure  $row       Renders one labelled row.
	 * @param Closure  $switch    Builds a checkbox control.
	 * @param Closure  $select    Builds a select control.
	 * @param Closure  $number    Builds a number control.
	 * @param Closure  $text      Builds a text control.
	 * @param Closure  $list      Builds a one-per-line textarea control.
	 */
	public function __construct(
		public readonly Settings $settings,
		public readonly Closure $overrides,
		public readonly Closure $row,
		public readonly Closure $switch,
		public readonly Closure $select,
		public readonly Closure $number,
		public readonly Closure $text,
		public readonly Closure $list
	) {
	}
}
