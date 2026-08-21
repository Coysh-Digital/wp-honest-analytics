<?php
/**
 * The privacy posture.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Privacy;

use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this configuration needs a cookie banner, and why.
 *
 * The question every site owner actually has, answered straight rather than as
 * a list of settings to interpret.
 *
 * This describes what the configuration *permits*, not what has happened. "A
 * cookie could be set" is the compliance-relevant fact - a site that has
 * enabled consented tracking but has not yet had a visitor agree still needs
 * the mechanism and the notice.
 */
final class Posture {

	private Settings $settings;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether this configuration requires a consent mechanism.
	 */
	public function needsBanner(): bool {
		return $this->settings->enableConsent;
	}

	/**
	 * The headline.
	 */
	public function title(): string {
		return $this->needsBanner()
			? __( 'This site sets a cookie, which needs a consent mechanism.', 'honest-analytics' )
			: __( 'Cookieless by default: designed not to require an analytics consent banner.', 'honest-analytics' );
	}

	/**
	 * The explanation under the headline.
	 */
	public function summary(): string {
		return $this->needsBanner()
			? __( 'Consented tracking is on, so visitors who agree receive a first-party cookie and a durable identifier. That belongs in your privacy notice, and needs a lawful basis - consent.', 'honest-analytics' )
			: __( 'No cookie is set, no device storage is used, and no IP address is written anywhere. Visitors are counted with a hash of a salt that is destroyed every 24 hours, so nothing retained can be traced back to anybody afterwards. What that means for your obligations is a question for your own advisers; this screen is here to give them the facts.', 'honest-analytics' );
	}

	/**
	 * Things worth checking, given how this site is configured.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		$warnings = [];

		if ( $this->settings->enableConsent ) {
			$warnings[] = __( 'Consented tracking is enabled, so this site sets a first-party cookie for visitors who agree. That needs a consent mechanism and a privacy notice entry: the cookieless default no longer applies.', 'honest-analytics' );
		}

		if ( $this->settings->enableJourneys ) {
			$warnings[] = __( 'The stored journeys layer is enabled. Individual page-by-page histories are kept for consented visitors - this is personal data, subject to access and erasure requests.', 'honest-analytics' );
		}

		if ( $this->settings->associateUserId ) {
			$warnings[] = __( 'Consented visitors are linked to their WordPress account, which makes the data directly identifiable rather than pseudonymous.', 'honest-analytics' );
		}

		if ( has_filter( 'honest_analytics_visitor_id' ) ) {
			$warnings[] = __( 'A plugin or theme supplies its own identifier for consented visitors through the extension API. That is no longer a random value meaningful only inside this plugin - it can be joined to your own records, which makes the consented data directly identifiable.', 'honest-analytics' );
		}

		if ( ! $this->settings->honourGpc ) {
			$warnings[] = __( 'Global Privacy Control is being ignored. GPC is treated as a valid opt-out under several privacy laws, and disregarding it is a legal risk.', 'honest-analytics' );
		}

		if ( $this->settings->saltRotationInterval > 86400 ) {
			$warnings[] = __( 'The visitor salt rotates less often than every 24 hours, which lengthens how long a visitor stays re-identifiable and weakens the case that this data is anonymous.', 'honest-analytics' );
		}

		if ( $this->settings->enableConsent && $this->settings->consentCookieDuration > 34186000 ) {
			$warnings[] = __( 'The consent cookie lasts longer than 13 months. Guidance in several jurisdictions expects consent to be refreshed at least annually.', 'honest-analytics' );
		}

		return $warnings;
	}

	/**
	 * What is stored, and what is deliberately not.
	 *
	 * @return array<int,array{yes:string,no:string}>
	 */
	public function collects(): array {
		return [
			[
				'yes' => __( 'The requested path, campaign parameters stripped', 'honest-analytics' ),
				'no'  => __( 'The IP address, in any form', 'honest-analytics' ),
			],
			[
				'yes' => sprintf(
					/* translators: %d: days of hourly detail. */
					__( 'The date, and the hour for the last %d days', 'honest-analytics' ),
					$this->settings->hourlyWindowDays
				),
				'no'  => __( 'The full user-agent string', 'honest-analytics' ),
			],
			[
				'yes' => __( 'Views, entrances, exits and bounces as counts', 'honest-analytics' ),
				'no'  => __( 'The full referrer URL', 'honest-analytics' ),
			],
			[
				'yes' => __( 'A small sketch of who was here today', 'honest-analytics' ),
				'no'  => __( 'Any per-visitor row', 'honest-analytics' ),
			],
			[
				'yes' => __( 'Browser, OS, device type and country as counts', 'honest-analytics' ),
				'no'  => __( 'Anything that survives the salt rotating', 'honest-analytics' ),
			],
		];
	}

	/**
	 * The identifiers this configuration holds.
	 *
	 * @return array<int,array{term:string,description:string}>
	 */
	public function identifiers(): array {
		$hours = round( $this->settings->saltRotationInterval / 3600, 1 );

		$out = [
			[
				'term'        => __( 'Daily visitor hash', 'honest-analytics' ),
				'description' => sprintf(
					/* translators: %s: hours between rotations. */
					__( 'Eight bytes of SHA-256 over the current salt, the address, the user agent and the site ID. Unlinkable once the salt rotates, every %s hours.', 'honest-analytics' ),
					$hours
				),
			],
		];

		$out[] = [
			'term'        => __( 'Consent cookie', 'honest-analytics' ),
			'description' => $this->settings->enableConsent
				? sprintf(
					/* translators: %s: cookie name. */
					__( 'A first-party cookie (%s), set only for visitors who affirmatively agree.', 'honest-analytics' ),
					$this->settings->consentCookieName
				)
				: __( 'Not in use. Consented tracking is switched off.', 'honest-analytics' ),
		];

		$out[] = [
			'term'        => __( 'Account link', 'honest-analytics' ),
			'description' => $this->settings->associateUserId
				? __( 'In use. Consented analytics is joined to WordPress user accounts.', 'honest-analytics' )
				: __( 'Not in use. Analytics is not joined to WordPress user accounts.', 'honest-analytics' ),
		];

		return $out;
	}

	/**
	 * What lawful basis this configuration implies.
	 */
	public function lawfulBasis(): string {
		return $this->needsBanner()
			? __( 'Consent for the identified layer. For the anonymous counters, whichever basis you and your advisers settle on - most operators reach for legitimate interests, or find that none is engaged.', 'honest-analytics' )
			: __( 'The stored counters hold no individual, and the hashes that produced them stopped being linkable when the salt rotated. Most operators treat that as outside the scope of consent requirements - but that conclusion is yours and your advisers’ to reach, from the facts on this screen.', 'honest-analytics' );
	}

	/**
	 * The facts table shown on the Privacy screen.
	 *
	 * @return array<string,string>
	 */
	public function facts(): array {
		$hours = round( $this->settings->saltRotationInterval / 3600, 1 );

		return [
			__( 'IP addresses stored', 'honest-analytics' ) => __( 'Never - hashed in memory and discarded', 'honest-analytics' ),
			__( 'Cookies set', 'honest-analytics' ) => $this->settings->enableConsent
				? sprintf(
					/* translators: %s: cookie name. */
					__( 'One, for consenting visitors only (%s)', 'honest-analytics' ),
					$this->settings->consentCookieName
				)
				: __( 'None', 'honest-analytics' ),
			__( 'Device storage used', 'honest-analytics' ) => __( 'None', 'honest-analytics' ),
			__( 'Data leaves the site', 'honest-analytics' ) => __( 'Never - no third party receives anything', 'honest-analytics' ),
			__( 'Visitor salt rotation', 'honest-analytics' ) => sprintf(
				/* translators: %s: hours. */
				__( 'Every %s hours, destroying the previous salt', 'honest-analytics' ),
				$hours
			),
			__( 'Global Privacy Control', 'honest-analytics' ) => $this->settings->honourGpc
				? __( 'Honoured', 'honest-analytics' )
				: __( 'Ignored', 'honest-analytics' ),
			__( 'Aggregate retention', 'honest-analytics' ) => sprintf(
				/* translators: %d: months. */
				_n( '%d month', '%d months', $this->settings->rollupRetentionMonths, 'honest-analytics' ),
				$this->settings->rollupRetentionMonths
			),
			__( 'Raw per-visitor rows', 'honest-analytics' ) => $this->settings->enableJourneys
				? sprintf(
					/* translators: %d: days. */
					_n( 'Yes, for consenting visitors, kept %d day', 'Yes, for consenting visitors, kept %d days', $this->settings->journeyRetentionDays, 'honest-analytics' ),
					$this->settings->journeyRetentionDays
				)
				: __( 'None', 'honest-analytics' ),
		];
	}
}
