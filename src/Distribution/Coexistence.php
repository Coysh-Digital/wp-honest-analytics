<?php
/**
 * Keeping the two builds out of each other's way.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Distribution;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only one copy runs at a time, and swapping them moves no data.
 *
 * Two packages can be installed side by side: one comes from the plugin
 * directory as `honest-analytics`, another may be installed by hand under a
 * different folder. They are separate plugin entries as far as WordPress is
 * concerned, so both can be active at once - and both would then define the
 * same constants, register the same hooks and drain the same spool.
 *
 * So: one stands down, the administrator is told plainly that nothing was
 * deleted, and which one stays is decided by
 * `honest_analytics_coexistence_precedence` rather than by either copy knowing
 * what the other is.
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
	 * Watch for another copy.
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
	 * Deactivate whichever copy should not be running.
	 */
	public static function standDown(): void {
		$other = self::otherCopy();

		if ( null === $other ) {
			return;
		}

		/**
		 * Filters this copy's precedence when two are installed.
		 *
		 * Both copies run this code and both reach their own answer, which is what
		 * stops them deactivating each other in turn: the one that carries more
		 * says so, the plain one does not, and they agree without talking.
		 *
		 * @param int $precedence Higher stays. Zero is the plain package.
		 */
		$precedence = (int) apply_filters( 'honest_analytics_coexistence_precedence', 0 );

		// Silently, in both branches. The deactivation hook clears the scheduled
		// events and those belong to the data, not to the copy - letting the one
		// standing down run its hook would unschedule the drain the other is about
		// to rely on.
		//
		// The third argument matters on a network: deactivating a network-activated
		// plugin per-site leaves it active network-wide and loading again on the
		// next request, which would be a stand-down that never happened.
		if ( $precedence > 0 ) {
			deactivate_plugins( $other, true, self::isNetworkActive( $other ) );

			set_transient( self::NOTICE, 'lite-stood-down', WEEK_IN_SECONDS );

			return;
		}

		deactivate_plugins( self::self(), true, self::isNetworkActive( self::self() ) );

		set_transient( self::NOTICE, 'lite-stood-down', WEEK_IN_SECONDS );
	}

	/**
	 * Whether a plugin is active for the whole network rather than this site.
	 *
	 * @param string $basename Plugin basename.
	 */
	private static function isNetworkActive( string $basename ): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$network = get_site_option( 'active_sitewide_plugins', [] );

		return is_array( $network ) && isset( $network[ $basename ] );
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
			esc_html__( 'Two copies of Honest Analytics were installed, so one has been deactivated.', 'honest-analytics' ),
			esc_html__( 'Nothing was deleted. Both use the same tables, so every figure you had before is still there and still counting.', 'honest-analytics' )
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
	 * Every plugin active on this site, per-site and network-wide alike.
	 *
	 * @return string[]
	 */
	private static function activePlugins(): array {
		$active = array_map( 'strval', (array) get_option( 'active_plugins', [] ) );

		if ( ! is_multisite() ) {
			return $active;
		}

		// Keys, not values: `active_sitewide_plugins` maps basename to the
		// timestamp it was network-activated at.
		$network = get_site_option( 'active_sitewide_plugins', [] );

		if ( is_array( $network ) ) {
			$active = array_merge( $active, array_map( 'strval', array_keys( $network ) ) );
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * The other copy's basename, if it is active.
	 *
	 * Matched on the entry file rather than the folder, because a folder can be
	 * renamed on the way in and a plugin that only recognises its twin by
	 * directory name would then miss it. The text domain confirms it, so an
	 * unrelated plugin that happens to share a filename is not caught.
	 */
	private static function otherCopy(): ?string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$mine = self::self();

		// Network-activated plugins are not in `active_plugins`; they are keys
		// of `active_sitewide_plugins`, a network option. Reading only the
		// first meant that on a network where either copy is network-activated
		// this returned null on every site, neither stood down, and
		// `honest-analytics.php` made whichever loaded second return before
		// defining anything. Network-activated plugins load first, so a
		// network-wide copy silently and permanently beat a per-site one.
		foreach ( self::activePlugins() as $basename ) {
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
