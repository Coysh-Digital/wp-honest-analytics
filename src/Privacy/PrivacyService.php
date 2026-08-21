<?php
/**
 * Subject access, erasure, and the posture.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Privacy;

use HonestAnalytics\Consent\ConsentState;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is held about a person, and what can be done about it.
 *
 * The scope of a subject request is exactly two tables: the consented journeys
 * and the consent log. The aggregate rollups are out of scope and that is not a
 * dodge - a HyperLogLog sketch cannot be interrogated for an individual, and the
 * salted hashes that fed it stopped being linkable to anyone the moment the salt
 * rotated. There is no row to find and none to remove.
 */
final class PrivacyService {

	private Settings $settings;

	/**
	 * @param Settings|null $settings Settings.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? SettingsRepository::get();
	}

	/**
	 * Everything held about one subject.
	 *
	 * @param string|null $visitorId Durable visitor id.
	 * @param int|null    $userId    WordPress user id.
	 *
	 * @return array<string,mixed>
	 */
	public function export( ?string $visitorId = null, ?int $userId = null ): array {
		global $wpdb;

		if ( null === $visitorId && null === $userId ) {
			return [
				'journeys' => [],
				'consent'  => [],
				'notes'    => $this->notes(),
			];
		}

		$journeys   = Tables::name( Tables::JOURNEYS );
		$dimensions = Tables::name( Tables::DIMENSIONS );

		$where = [];
		$args  = [];

		if ( null !== $visitorId ) {
			$where[] = 'j.visitorId = %s';
			$args[]  = $visitorId;
		}

		if ( null !== $userId ) {
			$where[] = 'j.userId = %d';
			$args[]  = $userId;
		}

		$clause = implode( ' OR ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$journeyRows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.occurredAt, d.value AS path, j.sessionId, j.sequence, j.siteId, j.userId
				FROM `$journeys` j
				LEFT JOIN `$dimensions` d ON d.id = j.pathDimId
				WHERE $clause
				ORDER BY j.occurredAt ASC, j.sequence ASC",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$consentRows = [];

		if ( null !== $visitorId ) {
			$consentLog = Tables::name( Tables::CONSENT_LOG );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$consentRows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT recordedAt, state, method, scope, policyVersion FROM `$consentLog`
					WHERE visitorId = %s ORDER BY recordedAt ASC",
					$visitorId
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return [
			'journeys' => (array) $journeyRows,
			'consent'  => (array) $consentRows,
			'notes'    => $this->notes(),
		];
	}

	/**
	 * Erase what is held about one subject.
	 *
	 * The consent record is kept by default, and that is deliberate: it is the
	 * evidence that the processing which has just been erased was lawful while
	 * it was happening. Removing it protects nobody and loses the only proof
	 * that the rules were followed.
	 *
	 * @param string|null $visitorId          Durable visitor id.
	 * @param int|null    $userId             WordPress user id.
	 * @param bool        $includeConsentLog  Also delete the consent record.
	 *
	 * @return array{journeys:int,consentLog:int}
	 */
	public function erase( ?string $visitorId = null, ?int $userId = null, bool $includeConsentLog = false ): array {
		global $wpdb;

		if ( null === $visitorId && null === $userId ) {
			return [
				'journeys'   => 0,
				'consentLog' => 0,
			];
		}

		$journeys = Tables::name( Tables::JOURNEYS );
		$removed  = 0;

		if ( null !== $visitorId ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$removed += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$journeys` WHERE visitorId = %s", $visitorId )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( null !== $userId ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$removed += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$journeys` WHERE userId = %d", $userId )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$consentRemoved = 0;

		if ( $includeConsentLog && null !== $visitorId ) {
			$consentLog = Tables::name( Tables::CONSENT_LOG );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$consentRemoved = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$consentLog` WHERE visitorId = %s", $visitorId )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return [
			'journeys'   => $removed,
			'consentLog' => $consentRemoved,
		];
	}

	/**
	 * How much consented data exists.
	 *
	 * @param int|null $siteId Restrict to one site.
	 *
	 * @return array<string,int>
	 */
	public function counts( ?int $siteId = null ): array {
		global $wpdb;

		$journeys   = Tables::name( Tables::JOURNEYS );
		$consentLog = Tables::name( Tables::CONSENT_LOG );

		$where = null !== $siteId ? $wpdb->prepare( 'WHERE siteId = %d', $siteId ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$visitors = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT visitorId) FROM `$journeys` $where" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$journeys` $where" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$grantedState = ConsentState::Granted->value;
		$deniedState  = ConsentState::Denied->value;

		$consentWhere = null !== $siteId ? $wpdb->prepare( 'AND siteId = %d', $siteId ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$grants = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `$consentLog` WHERE state = %s $consentWhere", $grantedState )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$denials = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `$consentLog` WHERE state = %s $consentWhere", $deniedState )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return [
			'consentedVisitors' => $visitors,
			'journeyRows'       => $rows,
			'consentGrants'     => $grants,
			'consentDenials'    => $denials,
		];
	}

	/**
	 * The current privacy posture.
	 */
	public function posture(): Posture {
		return new Posture( $this->settings );
	}

	/**
	 * The notes that accompany an export.
	 *
	 * @return string[]
	 */
	private function notes(): array {
		return [
			__( 'This export covers every record held about this subject: their consented page journeys and their consent history.', 'honest-analytics' ),
			__( 'Aggregate statistics - pageviews, unique-visitor estimates, sessions, sources, devices - are not included because they contain no personal data. They are counters and probabilistic sketches from which no individual can be identified or removed.', 'honest-analytics' ),
			__( 'No IP address is held, in any form. Addresses are hashed in memory during a request and discarded; the salt used to hash them is destroyed on rotation.', 'honest-analytics' ),
		];
	}
}
