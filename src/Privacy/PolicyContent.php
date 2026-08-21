<?php
/**
 * Suggested privacy policy text.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Privacy;

use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What to tell visitors, generated from what is actually switched on.
 *
 * Offered through WordPress's own privacy policy tool, so it lands where a site
 * owner is already writing their notice. It is generated from the live settings
 * rather than being boilerplate, because a paragraph claiming no cookies are set
 * on a site that has just enabled consented tracking is worse than no paragraph.
 */
final class PolicyContent {

	/**
	 * Register the suggested text.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( __( 'Honest Analytics', 'honest-analytics' ), self::content() );
	}

	/**
	 * The suggested text.
	 */
	public static function content(): string {
		$settings = SettingsRepository::get();
		$posture  = new Posture( $settings );
		$hours    = round( $settings->saltRotationInterval / 3600, 1 );

		$paragraphs = [];

		$paragraphs[] = __( 'This site measures its own traffic with Honest Analytics, which runs on this server. No analytics data is sent to any third party, and no analytics service holds a copy of it.', 'honest-analytics' );

		$paragraphs[] = sprintf(
			/* translators: %s: hours between salt rotations. */
			__( 'We do not store your IP address. When you view a page, your address is combined with your browser type and a secret value, turned into a short code, and then discarded - the address itself is never written to our database, our logs or our backups. The secret is replaced every %s hours and the old one destroyed, so the codes from one day cannot be matched against the codes from the next.', 'honest-analytics' ),
			$hours
		);

		if ( $settings->enableConsent ) {
			$paragraphs[] = sprintf(
				/* translators: %d: months the cookie lasts. */
				__( 'If you agree to it, we set a first-party cookie so we can recognise your browser on later visits. It lasts about %d months, it is only set once you have agreed, and you can withdraw at any time - doing so deletes what was collected under it.', 'honest-analytics' ),
				(int) round( $settings->consentCookieDuration / 2629800 )
			);
		} else {
			$paragraphs[] = __( 'Our analytics does not use cookies at all, and stores nothing on your device. There is nothing to opt out of, and nothing for you to dismiss.', 'honest-analytics' );
		}

		if ( $settings->enableGeo ) {
			$paragraphs[] = __( 'We record which country a visit came from, worked out on this server from a local database. No location service is contacted, and nothing more precise than a country is derived or kept.', 'honest-analytics' );
		}

		if ( $settings->honourGpc ) {
			$paragraphs[] = __( 'If your browser sends a Global Privacy Control signal, we honour it: you are not counted beyond the anonymous statistics, and nothing on this site can override that.', 'honest-analytics' );
		}

		$paragraphs[] = sprintf(
			/* translators: %d: months of retention. */
			_n(
				'The statistics we keep are counts - how many people saw a page, which country they were in, what kind of device they used. They are kept for %d month and then deleted.',
				'The statistics we keep are counts - how many people saw a page, which country they were in, what kind of device they used. They are kept for %d months and then deleted.',
				$settings->rollupRetentionMonths,
				'honest-analytics'
			),
			$settings->rollupRetentionMonths
		);

		if ( ! $posture->needsBanner() ) {
			$paragraphs[] = __( 'Because these counts contain no information about any individual, there is nothing in them for us to look up about you, and nothing for us to erase on request.', 'honest-analytics' );
		}

		$html = '';

		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p class="privacy-policy-tutorial">' . esc_html( $paragraph ) . '</p>';
		}

		return $html;
	}
}
