<?php
/**
 * Plugin Name:       Honest Analytics
 * Plugin URI:        https://honest-analytics.com
 * Description:       Privacy-first, cookieless analytics that live inside WordPress. No third-party service, no IP addresses, no per-visitor rows - just aggregate counters you own.
 * Version:           0.9.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Coysh Digital
 * Author URI:        https://coysh.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       honest-analytics
 * Domain Path:       /languages
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Two copies of this plugin can be active at once - the free build and the paid
 * one, during the few seconds of an upgrade, or after a manual upload that
 * landed in a second folder.
 *
 * The first to load owns the constants. This one stands down here rather than
 * redefining them, because PHP's answer to a redefined constant is a warning on
 * every request until somebody notices, and a plugin that fills a log is worse
 * than one that quietly waits. Distribution\Coexistence deactivates whichever
 * of the two should not be running on the next admin request.
 */
if ( defined( 'HONEST_ANALYTICS_FILE' ) ) {
	return;
}

const VERSION = '0.9.6';

define( 'HONEST_ANALYTICS_FILE', __FILE__ );
define( 'HONEST_ANALYTICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'HONEST_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );
define( 'HONEST_ANALYTICS_VERSION', VERSION );
define( 'HONEST_ANALYTICS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Which of the two builds this is.
 *
 * Stamped at package time by bin/build.sh. Undefined means the working tree,
 * which behaves as Pro so that development sees everything. Lite ships with it
 * false and with the Pro-only files removed altogether, so this constant is a
 * statement about the build rather than a switch that hides anything.
 */
define( 'HONEST_ANALYTICS_HAS_PRO', false );

/**
 * Refuse to run rather than fatal on an unsupported stack.
 *
 * A plugin that white-screens a site is worse than one that declines to load,
 * so the guard runs before the autoloader is even required.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: current PHP version. */
						__( 'Honest Analytics needs PHP 8.1 or newer. This site runs PHP %s, so the plugin has not loaded.', 'honest-analytics' ),
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

/**
 * Survive a half-finished update rather than fataling on one.
 *
 * The bundled autoloader is a classmap built at package time with
 * `--classmap-authoritative`, which makes it the whole truth about which of
 * this plugin's classes exist. Pair one build's classmap with another build's
 * source - by uploading over the top rather than replacing, or by a sync that
 * skips `vendor/` - and it is authoritatively wrong in both directions: it
 * cannot find a class that is there, and it insists on including one that is
 * not. The first screen that names a class the stale map has never heard of
 * takes the admin down with it.
 *
 * It happens, and not once: a site reporting this had been carrying 0.9.1's
 * `vendor/` under 0.9.4's source, so whatever updates that install had never
 * replaced that directory at all.
 *
 * `bin/build.sh` stamps this build's version into the root `reference` of
 * `vendor/composer/installed.php`, so the two halves can be compared for the
 * price of one `include`. Mismatched, the plugin's own PSR-4 autoloader goes in
 * *ahead* of the classmap - the source tree on disk is the one thing that is
 * certainly right - and the site goes on working while the notice explains what
 * to fix. The libraries beside the map still load from it, and every class that
 * uses one degrades rather than fatals if it turns out to be missing.
 *
 * Refusing to load was the first answer to this and it was the wrong one. A
 * plugin that declines to start registers no admin page, and WordPress answers
 * a request for one that was never registered with "Sorry, you are not allowed
 * to access this page" - a 403 raised before `admin_notices` renders, so the
 * explanation never reaches the person reading it.
 */
$honest_analytics_stale  = false;
$honest_analytics_vendor = HONEST_ANALYTICS_DIR . 'vendor/composer/installed.php';

if ( is_file( $honest_analytics_vendor ) ) {
	$honest_analytics_meta      = include $honest_analytics_vendor;
	$honest_analytics_reference = is_array( $honest_analytics_meta )
		? (string) ( $honest_analytics_meta['root']['reference'] ?? '' )
		: '';

	// Only when it looks like one of our stamped builds. A development tree has
	// the commit SHA composer put there and must go on loading normally.
	$honest_analytics_stale = '' !== $honest_analytics_reference
		&& 1 === preg_match( '/^\d+\.\d+\.\d+$/', $honest_analytics_reference )
		&& VERSION !== $honest_analytics_reference;
}

if ( $honest_analytics_stale ) {
	require_once HONEST_ANALYTICS_DIR . 'src/Support/Autoloader.php';
	Support\Autoloader::register( true );

	add_action(
		'admin_notices',
		static function () use ( $honest_analytics_reference ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: version of the plugin's own files, 2: version of the libraries bundled beside them. */
						__( 'Honest Analytics did not update cleanly: its own files are version %1$s and the libraries bundled beside them are version %2$s. It is running from its own source in the meantime, so nothing is lost, but the two halves should be brought back into line - delete the plugin and install it again. Removing the folder takes nothing with it; the analytics tables and settings are untouched.', 'honest-analytics' ),
						VERSION,
						$honest_analytics_reference
					)
				)
			);
		}
	);
}

if ( is_file( HONEST_ANALYTICS_DIR . 'vendor/autoload.php' ) ) {
	require_once HONEST_ANALYTICS_DIR . 'vendor/autoload.php';
} else {
	require_once HONEST_ANALYTICS_DIR . 'src/Support/Autoloader.php';
	Support\Autoloader::register();
}

// Functions, not classes: PSR-4 has nothing to say about them, and a theme
// calls them unqualified from the global namespace.
require_once HONEST_ANALYTICS_DIR . 'src/template-functions.php';

register_activation_hook( __FILE__, [ Schema\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Schema\Installer::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ Bootstrap::class, 'boot' ], 10 );
