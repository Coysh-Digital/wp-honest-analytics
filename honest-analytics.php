<?php
/**
 * Plugin Name:       Honest Analytics
 * Plugin URI:        https://honest-analytics.com
 * Description:       Privacy-first, cookieless analytics that live inside WordPress. No third-party service, no IP addresses, no per-visitor rows - just aggregate counters you own.
 * Version:           0.9.4
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

const VERSION = '0.9.4';

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
 * Refuse to run rather than fatal on a half-finished update.
 *
 * The autoloader is a classmap built at package time with
 * `--classmap-authoritative`, which means it is the whole truth about which
 * classes exist: a class it does not list cannot be found, and one it lists
 * whose file is missing produces two warnings and then is not found either.
 * That is the right trade for an intact install and a bad one for a mixed
 * install, where the first symptom is a fatal on whichever screen names a
 * class the stale map has never heard of.
 *
 * It happens. Updating by uploading over the top rather than replacing, an
 * extraction that stopped half way, a sync that skipped `vendor/` - all leave
 * one build's source beside another's classmap, and the plugin then takes the
 * admin down with it.
 *
 * `vendor/composer/installed.php` carries this build's version in its root
 * `reference`, stamped by `bin/build.sh`, so the two halves can be compared
 * for the price of one `include`. Mismatched, the plugin declines to load and
 * says what to do about it, which is the same bargain the PHP guard above
 * makes.
 */
$honest_analytics_vendor = HONEST_ANALYTICS_DIR . 'vendor/composer/installed.php';

if ( is_file( $honest_analytics_vendor ) ) {
	$honest_analytics_meta      = include $honest_analytics_vendor;
	$honest_analytics_reference = is_array( $honest_analytics_meta )
		? (string) ( $honest_analytics_meta['root']['reference'] ?? '' )
		: '';

	// Only when it looks like one of our stamped builds. A development tree
	// has a commit SHA here and must go on loading normally.
	if ( '' !== $honest_analytics_reference
		&& 1 === preg_match( '/^\d+\.\d+\.\d+$/', $honest_analytics_reference )
		&& VERSION !== $honest_analytics_reference
	) {
		add_action(
			'admin_notices',
			static function () use ( $honest_analytics_reference ): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: version of the plugin's own files, 2: version of its bundled libraries. */
							__( 'Honest Analytics has not loaded, because its update did not finish: the plugin files are version %1$s and the libraries beside them are version %2$s. Deleting the plugin and installing it again fixes this, and takes nothing with it - the analytics tables and settings are untouched by removing the folder.', 'honest-analytics' ),
							VERSION,
							$honest_analytics_reference
						)
					)
				);
			}
		);

		return;
	}
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
