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
	 * The option that remembers somebody said "not now" to the spool warning.
	 *
	 * Holds a timestamp, not a flag: dismissing this notice does not fix the
	 * exposure it describes, so it is a snooze, not a goodbye. Site-wide rather
	 * than per-admin, because whether the write spool is public is a fact about
	 * the server, not a preference - a second administrator re-warned a minute
	 * after the first one dismissed it would be noise, not safety.
	 */
	private const SPOOL_SNOOZE = 'honest_analytics_spool_snooze_until';

	/** How long a dismissal buys before the notice checks again. */
	private const SPOOL_SNOOZE_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * The option that remembers somebody said no to the spool warning for good.
	 *
	 * A flag beside the snooze timestamp, and site-wide for the same reason.
	 * This silences the banner only: the fault is still reported by
	 * {@see \HonestAnalytics\Scheduling\Health::problems()}, so it still
	 * reaches the CLI, the health filter, `isHealthy()` and the Settings screen,
	 * which is where somebody can actually act on it. Somebody who has looked at
	 * the exposure and decided it is handled - a rule in front of the site, a
	 * spool holding nothing they mind - should not be asked again every month
	 * for the life of the install.
	 */
	private const SPOOL_DISMISSED = 'honest_analytics_spool_dismissed';

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
		add_action( 'admin_init', [ self::class, 'handleSpoolSnooze' ] );
		add_action( 'admin_init', [ self::class, 'handleSpoolDismissal' ] );
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
	 * Snooze the spool warning, for a while.
	 *
	 * Not a permanent dismissal: the notice checks again once the snooze runs
	 * out, and only stays quiet then if the spool has actually stopped being
	 * public in the meantime.
	 */
	public static function handleSpoolSnooze(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['honest_dismiss_spool'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		check_admin_referer( 'honest-analytics-dismiss-spool' );

		update_option( self::SPOOL_SNOOZE, time() + self::SPOOL_SNOOZE_SECONDS, false );

		wp_safe_redirect( remove_query_arg( [ 'honest_dismiss_spool', '_wpnonce' ] ) );

		exit;
	}

	/**
	 * Put the spool warning away for good.
	 *
	 * Separate from the snooze rather than a parameter on it: the two are
	 * different decisions, and a query argument that could mean either would be
	 * one typo away from silencing a security warning somebody meant to defer.
	 */
	public static function handleSpoolDismissal(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['honest_dismiss_spool_forever'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		check_admin_referer( 'honest-analytics-dismiss-spool-forever' );

		update_option( self::SPOOL_DISMISSED, 'dismissed', false );

		wp_safe_redirect( remove_query_arg( [ 'honest_dismiss_spool_forever', '_wpnonce' ] ) );

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

		$health = new Health();

		// Asked once, outside the loop. `spoolPublic()` consults the loopback
		// verdict, so asking it per problem asked the same question as many
		// times as there were problems - and once a day, when that verdict has
		// expired, the first of those asks writes a probe file and makes two
		// HTTP requests with a five-second timeout apiece.
		$spoolPublic = $health->spoolPublic();

		foreach ( $health->problems() as $problem ) {
			// The spool warning gets its own dismissible rendering below - a
			// snoozed-but-still-true fault should stay off this screen without
			// stopping `problems()` from reporting it everywhere else that asks
			// (the CLI, the health filter, isHealthy()).
			if ( $spoolPublic && str_contains( $problem, 'write spool can be read' ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Honest Analytics:', 'honest-analytics' ),
				esc_html( $problem )
			);
		}

		self::renderSpoolNotice( $health );
		self::renderImportOffer( (string) $screen->id );
	}

	/**
	 * The spool warning, dismissible for a while or for good.
	 *
	 * Snoozing does not fix the exposure, so this reappears once the snooze
	 * runs out - but only if the spool is still actually public then. A site
	 * that fixed its nginx config in the meantime never sees it again.
	 *
	 * The second link puts it away permanently. That silences the banner and
	 * nothing else: {@see Health::problems()} still reports the fault to the
	 * CLI, the health filter and `isHealthy()`, and the Settings screen still
	 * says so in context - which is why {@see render()} stands down there. The
	 * warning stays findable by anybody who goes looking; it just stops
	 * interrupting somebody who has already decided about it.
	 *
	 * @param Health $health Health.
	 */
	private static function renderSpoolNotice( Health $health ): void {
		if ( ! $health->spoolPublic() ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		if ( 'dismissed' === get_option( self::SPOOL_DISMISSED, '' ) ) {
			return;
		}

		$snoozedUntil = (int) get_option( self::SPOOL_SNOOZE, 0 );

		if ( $snoozedUntil > time() ) {
			return;
		}

		$snooze = wp_nonce_url(
			add_query_arg( 'honest_dismiss_spool', '1' ),
			'honest-analytics-dismiss-spool'
		);

		$dismiss = wp_nonce_url(
			add_query_arg( 'honest_dismiss_spool_forever', '1' ),
			'honest-analytics-dismiss-spool-forever'
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html__( 'Honest Analytics:', 'honest-analytics' ),
			esc_html__( 'The write spool can be read over the web. It holds no addresses, but it is not public data. On nginx this needs a rule in the server config - the exact block is in the caching guide at https://github.com/Coysh-Digital/wp-honest-analytics/blob/main/docs/caching.md. On Apache or IIS, check that the .htaccess or web.config in the spool directory has not been removed.', 'honest-analytics' ),
			esc_url( $snooze ),
			esc_html__( 'Remind me again in 30 days', 'honest-analytics' ),
			esc_url( $dismiss ),
			esc_html__( 'Don’t show this again', 'honest-analytics' )
		);
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
