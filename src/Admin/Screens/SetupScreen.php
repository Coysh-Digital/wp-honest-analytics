<?php
/**
 * The first-run setup wizard.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Plugin;
use HonestAnalytics\Schema\Installer;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A one-minute welcome that sets the three things a new site tends to care
 * about, and gets out of the way.
 *
 * It is an invitation, not a gate: the plugin records visits on its defaults
 * from the moment it is activated, and every setting here also lives on the
 * full Settings screen. The wizard exists only so the choices that change a
 * privacy posture - how visits are counted, how long data is kept, which
 * browser signals are honoured - are put in front of someone once, in plain
 * words, rather than left to be discovered in a long form.
 *
 * The page has no menu row (see {@see \HonestAnalytics\Admin\Menu::build()});
 * it is reached from the welcome banner or by URL.
 */
final class SetupScreen extends Screen {

	/**
	 * The nonce action for the wizard form.
	 */
	private const NONCE = 'honest-analytics-setup';

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-setup';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Set up Honest Analytics', 'honest-analytics' );
	}

	/**
	 * Only people who can change what is collected.
	 */
	public function capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * Prose and a short form, not a report.
	 */
	public function maxWidth(): int {
		return 900;
	}

	/**
	 * Nothing here is time-bounded.
	 */
	public function hasRange(): bool {
		return false;
	}

	/**
	 * No charts.
	 */
	public function usesCharts(): bool {
		return false;
	}

	/**
	 * Handle the wizard form before anything renders.
	 *
	 * Saving here, rather than posting to options.php, keeps the whole flow in
	 * one place: validate the handful of fields, write them, record that setup
	 * is done, and hand the browser to the dashboard. Skipping writes no
	 * settings at all - it only puts the banner away.
	 */
	public function load(): void {
		parent::load();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		// parent::load() has already required Capabilities::MANAGE.

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$action = isset( $_POST['ha_setup_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['ha_setup_action'] ) ) : '';

		if ( 'finish' === $action ) {
			$this->finish();
		}

		// Anything else - the skip button, or a malformed post - closes the
		// wizard without touching a single setting.
		update_option( Installer::SETUP_OPTION, Installer::SETUP_DISMISSED, false );

		wp_safe_redirect( $this->dashboardUrl() );

		exit;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		View::render(
			'admin/setup',
			[
				'settings' => Plugin::instance()->settings(),
				'nonce'    => self::NONCE,
				'action'   => $this->pageUrl(),
				'docs'     => 'https://honest-analytics.com/docs/',
			]
		);
	}

	/**
	 * Save the three settings the wizard offers, then mark setup complete.
	 *
	 * The write goes through {@see SettingsRepository::update()}, which merges
	 * over what is stored, so nothing outside these three keys is disturbed.
	 */
	private function finish(): void {
		$values = self::sanitize(
			[
				// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in load().
				'trackingMode'          => isset( $_POST['trackingMode'] ) ? sanitize_key( wp_unslash( (string) $_POST['trackingMode'] ) ) : null,
				'rollupRetentionMonths' => isset( $_POST['rollupRetentionMonths'] ) ? (int) $_POST['rollupRetentionMonths'] : null,
				'honourGpc'             => isset( $_POST['honourGpc'] ),
				'honourDnt'             => isset( $_POST['honourDnt'] ),
				'excludeLoggedIn'       => isset( $_POST['excludeLoggedIn'] ),
				'keepDataOnUninstall'   => isset( $_POST['keepDataOnUninstall'] ),
				'blockCrawlers'         => isset( $_POST['blockCrawlers'] ),
				'stripQueryString'      => isset( $_POST['stripQueryString'] ),
				// phpcs:enable WordPress.Security.NonceVerification.Missing
			],
			Plugin::instance()->settings()
		);

		SettingsRepository::update( $values );

		update_option( Installer::SETUP_OPTION, Installer::SETUP_COMPLETE, false );

		wp_safe_redirect( add_query_arg( 'ha_welcome', '1', $this->dashboardUrl() ) );

		exit;
	}

	/**
	 * The three wizard settings, validated the way the Settings sanitiser would.
	 *
	 * Pure and side-effect-free, so the rules can be checked without a request:
	 * an unknown or missing tracking mode keeps the current one rather than
	 * being written, and the retention window is clamped to the ceiling the
	 * aggregate store is allowed to hold. A missing or null value for either
	 * leaves the current setting in place; every checkbox is a plain boolean,
	 * absent meaning off, because the wizard always renders each one.
	 *
	 * @param array<string,mixed> $input   Raw values, keyed by setting name.
	 * @param Settings            $current Settings as they stand.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input, Settings $current ): array {
		$mode = isset( $input['trackingMode'] ) ? (string) $input['trackingMode'] : '';

		if ( ! in_array( $mode, [ Settings::TRACKING_HYBRID, Settings::TRACKING_SERVER, Settings::TRACKING_CLIENT ], true ) ) {
			$mode = $current->trackingMode;
		}

		$months = isset( $input['rollupRetentionMonths'] ) ? (int) $input['rollupRetentionMonths'] : $current->rollupRetentionMonths;
		$months = max( 1, min( Settings::ROLLUP_MAX_RETENTION_MONTHS, $months ) );

		return [
			'trackingMode'          => $mode,
			'rollupRetentionMonths' => $months,
			'honourGpc'             => ! empty( $input['honourGpc'] ),
			'honourDnt'             => ! empty( $input['honourDnt'] ),
			'excludeLoggedIn'       => ! empty( $input['excludeLoggedIn'] ),
			'keepDataOnUninstall'   => ! empty( $input['keepDataOnUninstall'] ),
			'blockCrawlers'         => ! empty( $input['blockCrawlers'] ),
			'stripQueryString'      => ! empty( $input['stripQueryString'] ),
		];
	}

	/**
	 * This screen's own URL, where the form posts back to.
	 */
	private function pageUrl(): string {
		return add_query_arg( 'page', $this->slug(), admin_url( 'admin.php' ) );
	}

	/**
	 * The dashboard, where both paths out of the wizard land.
	 */
	private function dashboardUrl(): string {
		return add_query_arg( 'page', 'honest-analytics', admin_url( 'admin.php' ) );
	}
}
