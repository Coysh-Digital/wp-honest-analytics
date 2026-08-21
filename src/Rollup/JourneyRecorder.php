<?php
/**
 * The consented journeys layer.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Consent\ConsentService;
use HonestAnalytics\Dimensions\DimensionsService;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only per-visitor rows this plugin ever writes.
 *
 * Everything else in the database is a counter. This is a page-by-page history
 * belonging to an identifiable person, which is why it needs three switches on
 * at once - Pro, consent, and journeys - and why it is the only table whose size
 * grows with traffic rather than with cardinality.
 *
 * It is also the only data a subject access request has anything to find.
 */
final class JourneyRecorder {

	private Settings $settings;
	private ConsentService $consent;
	private DimensionsService $dimensions;

	/**
	 * @param Settings          $settings   Settings.
	 * @param ConsentService    $consent    Consent service.
	 * @param DimensionsService $dimensions Dimensions service.
	 */
	public function __construct( Settings $settings, ConsentService $consent, DimensionsService $dimensions ) {
		$this->settings   = $settings;
		$this->consent    = $consent;
		$this->dimensions = $dimensions;
	}

	/**
	 * Whether journeys are being recorded at all.
	 */
	public function isEnabled(): bool {
		return $this->settings->enableJourneys && $this->settings->enableConsent && Edition::isPro();
	}

	/**
	 * Record the steps in a batch of hits.
	 *
	 * @param Hit[] $hits Hits.
	 */
	public function record( array $hits ): void {
		global $wpdb;

		if ( ! $this->isEnabled() ) {
			return;
		}

		$candidates = array_values(
			array_filter(
				$hits,
				static fn ( Hit $hit ): bool => null !== $hit->visitorId && $hit->countView
			)
		);

		if ( [] === $candidates ) {
			return;
		}

		// Checked again here, not just at capture. The spool holds hits recorded
		// before somebody withdrew; without this the next drain writes them
		// straight back and silently resurrects data for a person who said no.
		$withdrawn = $this->consent->withdrawnVisitors(
			array_map( static fn ( Hit $hit ): string => (string) $hit->visitorId, $candidates )
		);

		$table     = Tables::name( Tables::JOURNEYS );
		$sequences = [];

		foreach ( $candidates as $hit ) {
			$visitorId = (string) $hit->visitorId;

			if ( isset( $withdrawn[ $visitorId ] ) ) {
				continue;
			}

			$key = $visitorId . '|' . $hit->sessionKey;

			if ( ! isset( $sequences[ $key ] ) ) {
				$sequences[ $key ] = $this->nextSequence( $visitorId, $hit->sessionKey );
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->insert(
				$table,
				[
					'visitorId'  => $visitorId,
					'siteId'     => $hit->siteId,
					'sessionId'  => $hit->sessionKey,
					'sequence'   => $sequences[ $key ],
					'pathDimId'  => $this->dimensions->getOrCreateId( DimensionType::Path, $hit->path ),
					'eventDimId' => null,
					'userId'     => $this->settings->associateUserId ? $hit->userId : null,
					'occurredAt' => gmdate( 'Y-m-d H:i:s', $hit->timestamp ),
				],
				[ '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s' ]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			++$sequences[ $key ];
		}
	}

	/**
	 * The next step number for a visit.
	 *
	 * Read from the table rather than counted in memory, so a visit spanning two
	 * drain batches keeps counting rather than restarting at one.
	 *
	 * @param string $visitorId Visitor id.
	 * @param string $sessionId Session key.
	 */
	private function nextSequence( string $visitorId, string $sessionId ): int {
		global $wpdb;

		$table = Tables::name( Tables::JOURNEYS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(sequence) FROM `$table` WHERE visitorId = %s AND sessionId = %s",
				$visitorId,
				$sessionId
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $max ? 0 : (int) $max + 1;
	}
}
