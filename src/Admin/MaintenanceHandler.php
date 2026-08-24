<?php
/**
 * Running maintenance from the browser.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Gc\GcService;
use HonestAnalytics\Plugin;
use HonestAnalytics\Schema\Upgrader;
use HonestAnalytics\Support\Losses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The buttons beside the scheduling banner.
 *
 * Everything here has a WP-CLI equivalent, and for a long time the CLI was the
 * only way to reach it. That is a fine answer for somebody with SSH and no
 * answer at all for the many WordPress sites administered entirely through the
 * browser, which is who this plugin is for.
 *
 * These are not a substitute for scheduling. The drain runs on its own, from
 * cron or from ordinary traffic; this is the button for the moment when
 * somebody wants to see it happen, or a batch has been set aside and needs
 * putting back.
 */
final class MaintenanceHandler {

	public const ACTION = 'honest_analytics_maintenance';
	public const NONCE  = 'honest-analytics-maintenance';

	/**
	 * Where the result of the last run is left for the redirect.
	 */
	private const NOTICE = 'honest_analytics_maintenance_notice';

	/**
	 * How long a browser-initiated drain is allowed to take.
	 *
	 * Well inside a default max_execution_time, and the drain resumes from
	 * where it stopped, so a large backlog clears over a few presses rather
	 * than one timeout.
	 */
	private const BUDGET_SECONDS = 20.0;

	/**
	 * Attach the handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * The form action.
	 */
	public static function url(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Take the result of the last run, once.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function takeNotice(): ?array {
		$key    = self::NOTICE . '_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return [
			'type'    => (string) $notice['type'],
			'message' => (string) $notice['message'],
		];
	}

	/**
	 * Run whichever task was asked for.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to run maintenance for this site.', 'honest-analytics' ),
				esc_html__( 'Not allowed', 'honest-analytics' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately above.
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( (string) $_POST['task'] ) ) : '';

		try {
			switch ( $task ) {
				case 'drain':
					self::drain( false );
					break;

				case 'retry':
					self::drain( true );
					break;

				case 'tidy':
					self::tidy();
					break;

				case 'losses':
					self::clearLosses();
					break;

				default:
					self::remember( 'error', __( 'That is not something this form does.', 'honest-analytics' ) );
			}
		} catch ( \Throwable $e ) {
			// A failure here is somebody's database, not their fault. Say what
			// happened rather than handing them a white screen.
			self::remember(
				'error',
				sprintf(
					/* translators: %s: an error message. */
					__( 'That did not finish: %s', 'honest-analytics' ),
					$e->getMessage()
				)
			);
		}

		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * Fold whatever is waiting into the reports.
	 *
	 * @param bool $retry Put set-aside batches back in the queue first.
	 */
	private static function drain( bool $retry ): void {
		$drainer  = Plugin::instance()->drainer();
		$restored = 0;

		if ( $retry ) {
			$restored = $drainer->retryFailed();
		}

		$result = $drainer->run( null, self::BUDGET_SECONDS );

		$parts = [];

		if ( $retry ) {
			$parts[] = sprintf(
				/* translators: %d: number of batches. */
				_n( '%d set-aside batch put back.', '%d set-aside batches put back.', $restored, 'honest-analytics' ),
				$restored
			);
		}

		// Two counts, two plural forms. One _n() keyed on the hits would have
		// written "4 hits folded in, from 1 batches".
		$parts[] = sprintf(
			/* translators: 1: a number of hits, for example "4 hits". 2: a number of batches, for example "1 batch". */
			__( '%1$s folded in, from %2$s.', 'honest-analytics' ),
			sprintf(
				/* translators: %s: a number. */
				_n( '%s hit', '%s hits', $result->hits, 'honest-analytics' ),
				number_format_i18n( $result->hits )
			),
			sprintf(
				/* translators: %s: a number. */
				_n( '%s batch', '%s batches', $result->batches, 'honest-analytics' ),
				number_format_i18n( $result->batches )
			)
		);

		if ( $result->quarantinedBatches > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of batches. */
				_n(
					'%d batch was set aside after repeated failures and is not counted.',
					'%d batches were set aside after repeated failures and are not counted.',
					$result->quarantinedBatches,
					'honest-analytics'
				),
				$result->quarantinedBatches
			);
		}

		if ( 0 === $result->hits && 0 === $result->batches ) {
			self::remember( 'success', __( 'Nothing was waiting. The reports are already up to date.', 'honest-analytics' ) );

			return;
		}

		self::remember(
			$result->hasFailures() ? 'error' : 'success',
			implode( ' ', $parts )
		);
	}

	/**
	 * Start the dropped-view count again.
	 *
	 * The count is cumulative and says nothing about whether the cause is still
	 * there, so somebody who has raised a ceiling or moved a spool needs to be
	 * able to see whether it worked. It counts views that were never recorded;
	 * clearing it forgets the tally, not any data.
	 */
	private static function clearLosses(): void {
		Losses::forget();

		self::remember(
			'success',
			__( 'The dropped-view count has been cleared. If views are still being dropped it will start again.', 'honest-analytics' )
		);
	}

	/**
	 * Compact, cap and delete what is past its retention.
	 */
	private static function tidy(): void {
		// The terminal-free route to a pending migration. Cron and the CLI are
		// the ordinary ones, but a site with neither would otherwise sit with
		// its drain stood down and no way for an administrator to do anything
		// about it - which would break the rule that nothing this plugin needs
		// doing requires a terminal. Pressing a button and waiting is a choice
		// somebody made; an ALTER inside an unrelated page load is not.
		Upgrader::maybeUpgrade();

		$summary = ( new GcService( Plugin::instance()->settings() ) )->run();

		// An empty summary means another tidy-up already holds the lock - cron,
		// an admin page load or the CLI. Reporting nought days and nought rows
		// would read as "there was nothing to do", which is a different and
		// wrong answer.
		if ( [] === $summary ) {
			self::remember(
				'success',
				__( 'A tidy-up is already running. It will finish on its own; there is nothing to do here.', 'honest-analytics' )
			);

			return;
		}

		$days = (int) ( $summary['compactedDays'] ?? 0 );

		// Everything the sweep removed, whatever it removed it from: expired
		// rollups, spent unique members, journeys, consent records, drain log
		// markers, key-value rows, abandoned sessions and orphan dimensions.
		$deleted = array_sum(
			array_map(
				'intval',
				array_diff_key( $summary, [ 'compactedDays' => true ] )
			)
		);

		self::remember(
			'success',
			sprintf(
				/* translators: 1: number of days, 2: number of rows. */
				__( 'Tidy-up finished. %1$s days compacted to daily totals, %2$s rows past their retention deleted.', 'honest-analytics' ),
				number_format_i18n( $days ),
				number_format_i18n( $deleted )
			)
		);
	}

	/**
	 * Leave a message for the screen the redirect lands on.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message What to say.
	 */
	private static function remember( string $type, string $message ): void {
		set_transient(
			self::NOTICE . '_' . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Where to go afterwards.
	 */
	private static function back(): string {
		$fallback = add_query_arg( [ 'page' => 'honest-analytics-settings' ], admin_url( 'admin.php' ) );
		$referer  = wp_get_referer();

		return false !== $referer ? wp_validate_redirect( $referer, $fallback ) : $fallback;
	}
}
