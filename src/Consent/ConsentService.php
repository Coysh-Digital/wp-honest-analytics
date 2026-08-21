<?php
/**
 * Consent resolution and recording.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Consent;

use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The second tier, and everything that has to be true before it opens.
 *
 * The anonymous tier needs no consent because there is no individual inside a
 * counter. This tier is different: it sets a cookie, keeps a durable
 * identifier, and can hold page-by-page histories. So it is off by default,
 * needs Pro, and needs somebody to have actually said yes.
 *
 * Precedence matters and is not negotiable:
 *
 * 1. A browser privacy signal wins outright. GPC is checked before anything
 *    else and is not passed to the extension filter - honouring a signal only
 *    when nobody objects is theatre.
 * 2. Our own cookie, which only exists because somebody agreed.
 * 3. The site's consent platform cookie. A value that is not on the accept list
 *    is a refusal, not a shrug: the visitor has been asked.
 * 4. Whatever a filter decides, for sites with their own arrangements.
 */
final class ConsentService {

	public const SCOPE = 'analytics';

	/** 128 bits. Long enough that guessing one is not a strategy. */
	private const VISITOR_ID_BYTES = 16;

	private Settings $settings;

	/**
	 * Resolved state per site, for this request.
	 *
	 * @var array<int,ConsentState>
	 */
	private array $stateMemo = [];

	/**
	 * Resolved visitor ids per site, for this request.
	 *
	 * @var array<int,string|null>
	 */
	private array $idMemo = [];

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the consented tier is switched on at all.
	 */
	public function isAvailable(): bool {
		return $this->settings->enableConsent && Edition::isPro();
	}

	/**
	 * The consent state for this request.
	 *
	 * @param int                  $siteId  Site ID.
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,string> $cookies Request cookies.
	 */
	public function resolve( int $siteId, array $headers = [], array $cookies = [] ): ConsentState {
		if ( isset( $this->stateMemo[ $siteId ] ) ) {
			return $this->stateMemo[ $siteId ];
		}

		$state = $this->determine( $headers, $cookies );

		$this->stateMemo[ $siteId ] = $state;

		return $state;
	}

	/**
	 * Work out the state.
	 *
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,string> $cookies Request cookies.
	 */
	private function determine( array $headers, array $cookies ): ConsentState {
		if ( ! $this->isAvailable() ) {
			return ConsentState::Denied;
		}

		// A signal outranks everything, including an affirmative click.
		if ( $this->settings->honourGpc && '1' === ( $headers['sec-gpc'] ?? '' ) ) {
			return ConsentState::Denied;
		}

		if ( $this->settings->honourDnt && '1' === ( $headers['dnt'] ?? '' ) ) {
			return ConsentState::Denied;
		}

		$state = ConsentState::Unknown;

		$ourCookie = $cookies[ $this->settings->consentCookieName ] ?? '';

		if ( self::isValidVisitorId( $ourCookie ) ) {
			$state = ConsentState::Granted;
		} elseif ( '' !== $this->settings->cmpCookieName ) {
			$cmp = $cookies[ $this->settings->cmpCookieName ] ?? null;

			if ( null !== $cmp ) {
				$accepted = array_map( 'strtolower', $this->settings->cmpCookieGrantedValues );
				$state    = in_array( strtolower( trim( $cmp ) ), $accepted, true )
					? ConsentState::Granted
					: ConsentState::Denied;
			}
		}

		/**
		 * Filters the resolved consent state.
		 *
		 * A browser privacy signal has already returned before this runs, and
		 * deliberately cannot be overridden here.
		 *
		 * @param ConsentState         $state   Resolved state.
		 * @param array<string,string> $cookies Request cookies.
		 * @param array<string,string> $headers Request headers.
		 */
		$filtered = apply_filters( 'honest_analytics_consent_state', $state, $cookies, $headers );

		return $filtered instanceof ConsentState ? $filtered : $state;
	}

	/**
	 * The durable identifier for a consented visitor, if there is one.
	 *
	 * @param int                  $siteId  Site ID.
	 * @param array<string,string> $cookies Request cookies.
	 */
	public function resolvedVisitorId( int $siteId, array $cookies = [] ): ?string {
		if ( array_key_exists( $siteId, $this->idMemo ) ) {
			return $this->idMemo[ $siteId ];
		}

		$id = null;

		if ( $this->isAvailable() ) {
			$cookie = $cookies[ $this->settings->consentCookieName ] ?? '';
			$id     = self::isValidVisitorId( $cookie ) ? $cookie : null;

			/**
			 * Filters the durable identifier used for a consented visitor.
			 *
			 * Supplying one joins analytics to the site's own records, which
			 * makes the consented layer directly identifiable rather than
			 * pseudonymous. The Privacy screen raises a warning when a handler
			 * is attached, because that is a change of legal character, not a
			 * configuration detail.
			 *
			 * @param string|null $id     Visitor identifier.
			 * @param int         $siteId Site ID.
			 */
			$supplied = apply_filters( 'honest_analytics_visitor_id', $id, $siteId );

			if ( is_string( $supplied ) && '' !== $supplied ) {
				if ( self::isValidExternalId( $supplied ) ) {
					$id = $supplied;
				} else {
					Log::warning( 'A supplied visitor id was refused: it must be 1-64 characters of A-Z, a-z, 0-9, dot, colon, underscore or hyphen.' );
				}
			}
		}

		$this->idMemo[ $siteId ] = $id;

		return $id;
	}

	/**
	 * Mint a new durable identifier.
	 */
	public static function newVisitorId(): string {
		return bin2hex( random_bytes( self::VISITOR_ID_BYTES ) );
	}

	/**
	 * Whether a string is one of our own visitor ids.
	 *
	 * @param string $id Candidate.
	 */
	public static function isValidVisitorId( string $id ): bool {
		return self::VISITOR_ID_BYTES * 2 === strlen( $id ) && ctype_xdigit( $id );
	}

	/**
	 * Whether a site-supplied id fits the column and cannot smuggle a separator.
	 *
	 * A shape check, not a security boundary.
	 *
	 * @param string $id Candidate.
	 */
	public static function isValidExternalId( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $id );
	}

	/**
	 * Record a consent decision.
	 *
	 * @param int           $siteId        Site ID.
	 * @param ConsentState  $state         Decision.
	 * @param ConsentMethod $method        How it was expressed.
	 * @param string|null   $visitorId     Durable id, when there is one.
	 * @param string|null   $visitorHash   Rotating hash, so a refusal can be
	 *                                     evidenced without a durable id.
	 */
	public function log(
		int $siteId,
		ConsentState $state,
		ConsentMethod $method,
		?string $visitorId = null,
		?string $visitorHash = null
	): void {
		global $wpdb;

		$table = Tables::name( Tables::CONSENT_LOG );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->insert(
			$table,
			[
				'siteId'        => $siteId,
				'visitorId'     => $visitorId,
				'visitorHash'   => $visitorHash,
				'state'         => $state->value,
				'method'        => $method->value,
				'scope'         => self::SCOPE,
				'policyVersion' => $this->settings->policyVersion,
				'recordedAt'    => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The visitors whose most recent decision was a refusal.
	 *
	 * Checked again at write time, because the spool holds hits captured before
	 * somebody withdrew: without this the next drain writes them straight back
	 * and silently resurrects data for a person who said no.
	 *
	 * @param string[] $visitorIds Candidate ids.
	 *
	 * @return array<string,true>
	 */
	public function withdrawnVisitors( array $visitorIds ): array {
		global $wpdb;

		$visitorIds = array_values( array_unique( array_filter( $visitorIds ) ) );

		if ( [] === $visitorIds ) {
			return [];
		}

		$table        = Tables::name( Tables::CONSENT_LOG );
		$placeholders = implode( ',', array_fill( 0, count( $visitorIds ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT visitorId, state FROM `$table` WHERE visitorId IN ($placeholders) ORDER BY recordedAt ASC, id ASC",
				$visitorIds
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$latest = [];

		foreach ( (array) $rows as $row ) {
			$latest[ (string) $row['visitorId'] ] = (string) $row['state'];
		}

		$withdrawn = [];

		foreach ( $latest as $id => $state ) {
			if ( ConsentState::Denied->value === $state ) {
				$withdrawn[ $id ] = true;
			}
		}

		return $withdrawn;
	}

	/**
	 * Forget the memoised state. For tests.
	 */
	public function flush(): void {
		$this->stateMemo = [];
		$this->idMemo    = [];
	}
}
