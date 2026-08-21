<?php
/**
 * Retention and cleanup.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Gc;

use DateTimeImmutable;
use HonestAnalytics\Plugin;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\DbKeyValueStore;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The nightly tidy-up.
 *
 * Compaction first, then everything past its retention window, then the
 * dimensions nothing references any more.
 *
 * Deletes are batched rather than issued as one enormous statement. The first
 * run after a retention period elapses is the big one, and a single DELETE
 * there would hold locks against the drain for as long as it took.
 */
final class GcService {

	private const DELETE_CHUNK = 5000;
	private const ORPHAN_CHUNK = 1000;

	private Settings $settings;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run everything.
	 *
	 * @param int|null $now Timestamp.
	 *
	 * @return array<string,int>
	 */
	public function run( ?int $now = null ): array {
		$now = $now ?? time();

		$compactor = Plugin::instance()->compactor();
		$days      = $compactor->run( $now );

		$compactor->discardFolded();

		$result = [
			'compactedDays'         => $days,
			'expiredRollups'        => $this->deleteExpiredRollups( $now ),
			'expiredMembers'        => $this->deleteExpiredUniqueMembers( $now ),
			'expiredJourneys'       => $this->deleteExpiredJourneys( $now ),
			'expiredConsentRecords' => $this->deleteExpiredConsentRecords( $now ),
			'prunedDrainLog'        => $this->pruneDrainLog( $now ),
			'sweptKeyValues'        => ( new DbKeyValueStore() )->sweep(),
			'closedSessions'        => $this->pruneAbandonedSessions( $now ),
			'orphanedDimensions'    => $this->deleteOrphanedDimensions(),
		];

		update_option( 'honest_analytics_last_gc', array_merge( $result, [ 'at' => $now ] ), false );

		return $result;
	}

	/**
	 * What a run would delete, without deleting any of it.
	 *
	 * Retention changes take effect at the next tidy-up and cannot be undone,
	 * so there has to be a way to see the size of the decision before making
	 * it. Counts rather than deletes; runs the same predicates the sweep does.
	 *
	 * @param int|null $now Timestamp.
	 *
	 * @return array<string,int|string>
	 */
	public function preview( ?int $now = null ): array {
		$now = $now ?? time();

		$rollupCutoff  = $this->retentionCutoff( $now );
		$memberCutoff  = ( new DateTimeImmutable( '@' . ( $now - $this->settings->saltRotationInterval * 2 ) ) )
			->setTimezone( Timezone::site() )
			->format( 'Y-m-d' );
		$journeyDays   = max( 1, min( Settings::JOURNEY_MAX_RETENTION_DAYS, $this->settings->journeyRetentionDays ) );
		$journeyCutoff = gmdate( 'Y-m-d H:i:s', $now - $journeyDays * 86400 );

		$rollups = 0;

		foreach ( Tables::expiringRollups() as $table ) {
			$rollups += $this->countMatching( $table, 'date < %s', [ $rollupCutoff ] );
		}

		$result = [
			'rollupCutoff'    => $rollupCutoff,
			'expiredRollups'  => $rollups,
			'expiredMembers'  => $this->countMatching( Tables::UNIQUE_MEMBERS, 'date < %s', [ $memberCutoff ] ),
			'expiredJourneys' => $this->countMatching( Tables::JOURNEYS, 'occurredAt < %s', [ $journeyCutoff ] ),
		];

		if ( $this->settings->consentLogRetentionDays > 0 ) {
			$consentCutoff                   = gmdate( 'Y-m-d H:i:s', $now - $this->settings->consentLogRetentionDays * 86400 );
			$result['expiredConsentRecords'] = $this->countMatching( Tables::CONSENT_LOG, 'recordedAt < %s', [ $consentCutoff ] );
		} else {
			$result['expiredConsentRecords'] = 0;
		}

		return $result;
	}

	/**
	 * How many rows a predicate matches.
	 *
	 * @param string           $table Unprefixed table name.
	 * @param string           $where Predicate, with placeholders.
	 * @param array<int,mixed> $args  Values for the placeholders.
	 */
	private function countMatching( string $table, string $where, array $args ): int {
		global $wpdb;

		$name = Tables::name( $table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and the placeholders live in the predicate the caller passed.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `$name` WHERE $where", $args )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * The oldest date aggregates are kept for.
	 *
	 * @param int $now Timestamp.
	 */
	public function retentionCutoff( int $now ): string {
		$months = max( 1, min( Settings::ROLLUP_MAX_RETENTION_MONTHS, $this->settings->rollupRetentionMonths ) );

		return ( new DateTimeImmutable( '@' . $now ) )
			->setTimezone( Timezone::site() )
			->modify( '-' . $months . ' months' )
			->format( 'Y-m-d' );
	}

	/**
	 * Delete aggregates past the retention window.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteExpiredRollups( int $now ): int {
		$cutoff  = $this->retentionCutoff( $now );
		$removed = 0;

		foreach ( Tables::expiringRollups() as $table ) {
			$removed += $this->deleteInBatches( $table, 'date < %s', [ $cutoff ] );
		}

		return $removed;
	}

	/**
	 * Delete exact-count membership rows whose salt is long gone.
	 *
	 * Once the salt that produced these hashes has been destroyed they match
	 * nothing - not for us, not for anyone.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteExpiredUniqueMembers( int $now ): int {
		$cutoff = ( new DateTimeImmutable( '@' . ( $now - $this->settings->saltRotationInterval * 2 ) ) )
			->setTimezone( Timezone::site() )
			->format( 'Y-m-d' );

		return $this->deleteInBatches( Tables::UNIQUE_MEMBERS, 'date < %s', [ $cutoff ] );
	}

	/**
	 * Delete journeys past their retention window.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteExpiredJourneys( int $now ): int {
		$days   = max( 1, min( Settings::JOURNEY_MAX_RETENTION_DAYS, $this->settings->journeyRetentionDays ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );

		return $this->deleteInBatches( Tables::JOURNEYS, 'occurredAt < %s', [ $cutoff ] );
	}

	/**
	 * Delete consent records past their retention window.
	 *
	 * Zero means keep them indefinitely, and that is the default: the record is
	 * evidence that processing already carried out was lawful.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteExpiredConsentRecords( int $now ): int {
		if ( $this->settings->consentLogRetentionDays <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', $now - $this->settings->consentLogRetentionDays * 86400 );

		return $this->deleteInBatches( Tables::CONSENT_LOG, 'recordedAt < %s', [ $cutoff ] );
	}

	/**
	 * Trim the drain log.
	 *
	 * @param int $now Timestamp.
	 */
	private function pruneDrainLog( int $now ): int {
		return $this->deleteInBatches( Tables::DRAIN_LOG, 'committedAt < %s', [ gmdate( 'Y-m-d H:i:s', $now - 7 * 86400 ) ] );
	}

	/**
	 * Remove sessions left behind by a failed close.
	 *
	 * @param int $now Timestamp.
	 */
	private function pruneAbandonedSessions( int $now ): int {
		global $wpdb;

		// A day past the point where the drain should have closed them. Anything
		// still here was orphaned by a crash, and it has already contributed
		// nothing rather than contributing twice.
		$cutoff = $now - max( 86400, $this->settings->sessionWindowSeconds() * 24 );
		$table  = Tables::name( Tables::SESSIONS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `$table` WHERE lastSeenAt < %d LIMIT %d", $cutoff, self::DELETE_CHUNK )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete dimensions nothing references any more.
	 *
	 * Walked by primary key in chunks rather than by building one enormous NOT
	 * IN list - which is exactly the shape that falls over on the cardinality
	 * spike this exists to clean up after.
	 */
	private function deleteOrphanedDimensions(): int {
		global $wpdb;

		$dimensions = Tables::name( Tables::DIMENSIONS );
		$references = Tables::dimensionReferences();
		$removed    = 0;
		$lastId     = 0;

		while ( true ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM `$dimensions` WHERE id > %d ORDER BY id ASC LIMIT %d",
					$lastId,
					self::ORPHAN_CHUNK
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$ids = array_map( 'intval', (array) $ids );

			if ( [] === $ids ) {
				return $removed;
			}

			$lastId = (int) max( $ids );
			$orphan = array_fill_keys( $ids, true );

			foreach ( $references as [ $table, $column ] ) {
				if ( [] === $orphan ) {
					break;
				}

				$name         = Tables::name( $table );
				$column       = preg_replace( '/[^A-Za-z0-9_]/', '', $column );
				$candidates   = array_keys( $orphan );
				$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%d' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$used = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT `$column` FROM `$name` WHERE `$column` IN ($placeholders)",
						$candidates
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

				foreach ( (array) $used as $id ) {
					unset( $orphan[ (int) $id ] );
				}
			}

			if ( [] === $orphan ) {
				continue;
			}

			$deleteIds    = array_keys( $orphan );
			$placeholders = implode( ',', array_fill( 0, count( $deleteIds ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$removed += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$dimensions` WHERE id IN ($placeholders)", $deleteIds )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
	}

	/**
	 * Delete rows in bounded batches.
	 *
	 * Selected by primary key rather than using DELETE ... LIMIT, which is
	 * MySQL-only and would not survive a move to another engine.
	 *
	 * @param string  $table  Unprefixed table name.
	 * @param string  $where  Where clause with placeholders.
	 * @param mixed[] $args   Placeholder values.
	 * @param string  $keyCol Primary key column.
	 */
	private function deleteInBatches( string $table, string $where, array $args, string $keyCol = 'id' ): int {
		global $wpdb;

		$name    = Tables::name( $table );
		$keyCol  = preg_replace( '/[^A-Za-z0-9_]/', '', $keyCol );
		$removed = 0;

		while ( true ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT `$keyCol` FROM `$name` WHERE $where LIMIT %d",
					array_merge( $args, [ self::DELETE_CHUNK ] )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

			$ids = array_filter( array_map( 'intval', (array) $ids ) );

			if ( [] === $ids ) {
				return $removed;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$deleted = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$name` WHERE `$keyCol` IN ($placeholders)", $ids )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			if ( $deleted <= 0 ) {
				Log::warning( 'Retention delete made no progress on ' . $table . '; stopping.' );

				return $removed;
			}

			$removed += $deleted;

			if ( count( $ids ) < self::DELETE_CHUNK ) {
				return $removed;
			}
		}
	}
}
