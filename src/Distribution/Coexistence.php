<?php
/**
 * Keeping the two builds out of each other's way.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Distribution;

use HonestAnalytics\Edition\Edition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only one edition runs at a time, and swapping them moves no data.
 *
 * The free build comes from wordpress.org as `honest-analytics` and the paid
 * one is a direct download as `honest-analytics-pro`. They are separate plugin
 * folders, so nothing stops somebody activating both - at which point the
 * second to load redefines constants the first already defined, and the site
 * fills up with notices while one of the two silently does nothing.
 *
 * So: Pro wins, Lite stands down, and the administrator is told plainly that
 * nothing was deleted. That last part is the whole point of the notice. Both
 * builds read and write the same tables, the same options, the same cache
 * groups and the same scheduled events - none of which are derived from the
 * plugin slug - so history collected under one appears unchanged under the
 * other, in either direction.
 */
final class Coexistence {

	/**
	 * Where the one-time notice waits to be shown.
	 */
	private const NOTICE = 'honest_analytics_coexistence';

	/**
	 * The main file both builds are named after, whatever their folder.
	 */
	private const ENTRY = '/honest-analytics.php';

	/**
	 * Watch for the other edition.
	 */
	public static function register(): void {
		// Only in the admin: deactivating a plugin needs wp-admin includes, and
		// the person who has to read the notice is here anyway. A front-end
		// request with both active is noisy for one page load and then fixed.
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', [ self::class, 'standDown' ], 5 );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	/**
	 * Deactivate whichever edition should not be running.
	 */
	public static function standDown(): void {
		$other = self::otherEdition();

		if ( null === $other ) {
			return;
		}

		// Pro is the paid build and the one with more to offer, so it is the
		// one that stays. Whichever of the two notices this, the outcome is
		// the same, which is what stops them deactivating each other in turn.
		// Silently, in both branches. The deactivation hook clears the scheduled
		// events and those belong to the data, not to the build - letting Lite
		// run its hook on the way out would unschedule the drain that Pro is
		// about to rely on.
		if ( Edition::hasPro() ) {
			deactivate_plugins( $other, true );

			set_transient( self::NOTICE, 'lite-stood-down', WEEK_IN_SECONDS );

			return;
		}

		deactivate_plugins( self::self(), true );

		set_transient( self::NOTICE, 'lite-stood-down', WEEK_IN_SECONDS );
	}

	/**
	 * Show the notice once, then forget it.
	 */
	public static function notice(): void {
		if ( ! get_transient( self::NOTICE ) ) {
			return;
		}

		delete_transient( self::NOTICE );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Honest Analytics Pro is running, so the free edition has been deactivated.', 'honest-analytics' ),
			esc_html__( 'Nothing was deleted. Both editions use the same tables, so every figure you had before is still there and still counting.', 'honest-analytics' )
		);
	}

	/**
	 * This build's own plugin basename.
	 */
	private static function self(): string {
		return defined( 'HONEST_ANALYTICS_BASENAME' )
			? (string) constant( 'HONEST_ANALYTICS_BASENAME' )
			: '';
	}

	/**
	 * The other edition's basename, if it is active.
	 *
	 * Matched on the entry file rather than the folder, because a folder can be
	 * renamed on the way in and a plugin that only recognises its twin by
	 * directory name would then miss it. The text domain confirms it, so an
	 * unrelated plugin that happens to share a filename is not caught.
	 */
	private static function otherEdition(): ?string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$mine = self::self();

		foreach ( (array) get_option( 'active_plugins', [] ) as $basename ) {
			$basename = (string) $basename;

			if ( $basename === $mine || ! str_ends_with( $basename, self::ENTRY ) ) {
				continue;
			}

			$file = WP_PLUGIN_DIR . '/' . $basename;

			if ( ! is_file( $file ) ) {
				continue;
			}

			$data = get_plugin_data( $file, false, false );

			if ( 'honest-analytics' === ( $data['TextDomain'] ?? '' ) ) {
				return $basename;
			}
		}

		return null;
	}
}
