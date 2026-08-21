<?php
/**
 * Admin notices.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Import\ImporterInterface;
use HonestAnalytics\Scheduling\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Says something only when something is wrong.
 *
 * Confined to the plugin's own screens: a notice about somebody else's cron
 * configuration on every page of the admin is how plugins become resented.
 */
final class Notices {

	/**
	 * The option that remembers somebody said no.
	 */
	private const IMPORT_PROMPT = 'honest_analytics_import_prompt';

	/**
	 * Where the detection answer is cached between page loads.
	 *
	 * Suffixed, because what is stored here changed shape: it used to be a list
	 * of names and is now id => name, and a transient written by the previous
	 * version would otherwise be read as the new thing.
	 */
	private const IMPORT_CACHE = 'honest_analytics_import_found_2';

	/**
	 * Attach the hook.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
		add_action( 'admin_init', [ self::class, 'handleDismissal' ] );
	}

	/**
	 * Put the import offer away, permanently.
	 *
	 * Once. A prompt that comes back is not an offer, it is nagging, and this
	 * plugin does not do that.
	 */
	public static function handleDismissal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['honest_dismiss_import'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		check_admin_referer( 'honest-analytics-dismiss-import' );

		update_option( self::IMPORT_PROMPT, 'dismissed', false );

		wp_safe_redirect( remove_query_arg( [ 'honest_dismiss_import', '_wpnonce' ] ) );

		exit;
	}

	/**
	 * Render the notices.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! str_contains( (string) $screen->id, 'honest-analytics' ) ) {
			return;
		}

		// The settings screen shows the same information in place, in context,
		// where somebody can act on it.
		if ( str_contains( (string) $screen->id, 'honest-analytics-settings' ) ) {
			return;
		}

		foreach ( ( new Health() )->problems() as $problem ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Honest Analytics:', 'honest-analytics' ),
				esc_html( $problem )
			);
		}

		self::renderImportOffer( (string) $screen->id );
	}

	/**
	 * Offer to bring somebody's history across, once.
	 *
	 * Shown when another analytics tool is found on the site and no import has
	 * been run yet. It is an offer, not a task: dismissing it is one click and
	 * it never returns, and the screen it points at is always there under
	 * Analytics anyway.
	 *
	 * @param string $screenId The current admin screen.
	 */
	private static function renderImportOffer( string $screenId ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		// Not on the import screen itself, where it would be telling somebody
		// something they are already looking at.
		if ( str_contains( $screenId, 'honest-analytics-import' ) ) {
			return;
		}

		if ( 'dismissed' === get_option( self::IMPORT_PROMPT, '' ) ) {
			return;
		}

		$found = self::importableSources();

		if ( [] === $found ) {
			return;
		}

		$url = add_query_arg(
			[ 'page' => 'honest-analytics-import' ],
			admin_url( 'admin.php' )
		);

		$dismiss = wp_nonce_url(
			add_query_arg( 'honest_dismiss_import', '1' ),
			'honest-analytics-dismiss-import'
		);

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p></div>',
			esc_html__( 'Keep your analytics history.', 'honest-analytics' ),
			esc_html(
				sprintf(
					/* translators: %s: a list of analytics tool names. */
					__( 'We found analytics from %s on this site. Would you like to bring that history across, so your charts do not start from zero?', 'honest-analytics' ),
					implode( ', ', $found )
				)
			),
			esc_url( $url ),
			esc_html__( 'Import analytics', 'honest-analytics' ),
			esc_url( $dismiss ),
			esc_html__( 'I’ll do this later', 'honest-analytics' )
		);
	}

	/**
	 * The names of any sources found here that have not been imported yet.
	 *
	 * Two questions, cached differently on purpose. What other analytics tools
	 * are installed changes rarely and costs a query against somebody else's
	 * schema, so it is cached for an hour. Whether their history has been
	 * brought across changes the moment somebody starts an import, and is asked
	 * every time - one indexed query, on this plugin's own screens only. That
	 * is what makes the offer disappear when it has been taken up, rather than
	 * up to an hour later.
	 *
	 * @return string[]
	 */
	private static function importableSources(): array {
		$found = self::detectedSources();

		if ( [] === $found ) {
			return [];
		}

		foreach ( self::handledSources() as $id ) {
			unset( $found[ $id ] );
		}

		return array_values( $found );
	}

	/**
	 * Other analytics tools installed here, as id => name.
	 *
	 * @return array<string,string>
	 */
	private static function detectedSources(): array {
		$cached = get_transient( self::IMPORT_CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$registry = '\\HonestAnalytics\\Import\\ImporterRegistry';
		$found    = [];

		if ( class_exists( $registry ) ) {
			foreach ( (array) $registry::all() as $importer ) {
				if ( ! $importer instanceof ImporterInterface ) {
					continue;
				}

				try {
					if ( $importer->detect()->isAvailable() ) {
						$found[ $importer->id() ] = $importer->name();
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		set_transient( self::IMPORT_CACHE, $found, HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * Sources this site has already imported, or is importing now.
	 *
	 * @return string[]
	 */
	private static function handledSources(): array {
		$class = '\\HonestAnalytics\\Import\\ImportRepository';

		if ( ! class_exists( $class ) ) {
			return [];
		}

		try {
			return ( new $class() )->handledSources( get_current_blog_id() );
		} catch ( \Throwable $e ) {
			// A missing table is the only realistic cause, and an upgrade that
			// has not run yet should not take an admin screen down with it.
			return [];
		}
	}
}
