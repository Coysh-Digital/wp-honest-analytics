<?php
/**
 * Plugin Name:       Honest Analytics
 * Plugin URI:        https://coysh.digital/plugins/honest-analytics
 * Description:       Privacy-first, cookieless analytics that live inside WordPress. No third-party service, no IP addresses, no per-visitor rows - just aggregate counters you own.
 * Version:           0.1.0
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

const VERSION = '0.1.0';

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
