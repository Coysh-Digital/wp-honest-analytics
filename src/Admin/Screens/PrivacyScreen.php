<?php
/**
 * The Privacy screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Plugin;
use HonestAnalytics\Privacy\PrivacyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does this configuration need a consent mechanism, and what is held.
 *
 * Leads with the answer rather than with a list of settings to interpret,
 * because that is the question every site owner actually has.
 */
final class PrivacyScreen extends Screen {

	/**
	 * The result of a subject request, if one was just made.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $requestResult = null;

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-privacy';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Privacy', 'honest-analytics' );
	}

	/**
	 * Prose reads badly at report width.
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
	 * Handle a subject access or erasure request.
	 */
	public function load(): void {
		parent::load();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['honest_analytics_subject'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to run subject requests.', 'honest-analytics' ),
				esc_html__( 'Not allowed', 'honest-analytics' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'honest-analytics-subject' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$subject = sanitize_text_field( wp_unslash( (string) ( $_POST['subject'] ?? '' ) ) );
		$action  = sanitize_key( wp_unslash( (string) ( $_POST['honest_analytics_subject'] ?? '' ) ) );
		$keepLog = ! isset( $_POST['include_consent_log'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $subject ) {
			return;
		}

		$service = new PrivacyService();
		$userId  = null;

		if ( is_email( $subject ) ) {
			$user   = get_user_by( 'email', $subject );
			$userId = $user ? (int) $user->ID : null;
		}

		$visitorId = null === $userId ? $subject : null;

		$this->requestResult = 'erase' === $action
			? [
				'action' => 'erase',
				'result' => $service->erase( $visitorId, $userId, ! $keepLog ),
			]
			: [
				'action' => 'export',
				'result' => $service->export( $visitorId, $userId ),
			];
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$plugin  = Plugin::instance();
		$service = new PrivacyService( $plugin->settings() );

		View::render(
			'admin/privacy',
			[
				'params'    => $this->params(),
				'posture'   => $service->posture(),
				'counts'    => $service->counts( $this->siteId() ),
				'settings'  => $plugin->settings(),
				'isPro'     => Edition::isPro(),
				'result'    => $this->requestResult,
				'canManage' => current_user_can( Capabilities::MANAGE ),
			]
		);
	}
}
