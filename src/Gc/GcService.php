<?php
/**
 * Retention and cleanup.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Gc;

use DateTimeImmutable;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Plugin;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\DbKeyValueStore;
use HonestAnalytics\Support\Db;
use HonestAnalytics\Support\Lock;
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

	/** When the orphaned-dimension sweep last ran. Weekly, not nightly. */
	private const ORPHAN_SWEEP_OPTION = 'honest_analytics_last_orphan_sweep';
	private const ORPHAN_CHUNK        = 1000;

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
	 * Under the drain's lock, because two of these run at once perfectly
	 * ordinarily - cron, the fallback on an admin page load, the button on
	 * Settings and the CLI command are four ways in, and none of them knew
	 * about the others. Two runs compacting the same day both read it, both
	 * delete it and both insert their own fold of it, and they race on the
	 * counters the fold is meant to discard.
	 *
	 * @param int|null $now Timestamp.
	 *
	 * @return array<string,int> Empty when another run already holds the lock.
	 */
	public function run( ?int $now = null ): array {
		$lock = new Lock( 'drain' );

		if ( ! $lock->acquire() ) {
			return [];
		}

		try {
			return $this->sweep( $now ?? time() );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Everything a run does, once the lock is held.
	 *
	 * @param int $now Timestamp.
	 *
	 * @return array<string,int>
	 */
	private function sweep( int $now ): array {
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
			'orphanedDimensions'    => $this->deleteOrphanedDimensions( $now ),
			'expiredShareLinks'     => $this->deleteExpiredShareLinks( $now ),
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

		$sourced = array_flip( Tables::sourcedRollups() );
		$rollups = 0;

		foreach ( Tables::expiringRollups() as $table ) {
			$rollups += isset( $sourced[ $table ] )
				? $this->countMatching( $table, 'date < %s AND source = %s', [ $rollupCutoff, ImportSource::NATIVE ] )
				: $this->countMatching( $table, 'date < %s', [ $rollupCutoff ] );
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
	 * Rows imported from elsewhere are exempt: a table listed in
	 * {@see Tables::sourcedRollups()} only loses its native rows here, because
	 * imported history is kept regardless of the retention setting.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteExpiredRollups( int $now ): int {
		$cutoff  = $this->retentionCutoff( $now );
		$sourced = array_flip( Tables::sourcedRollups() );
		$removed = 0;

		foreach ( Tables::expiringRollups() as $table ) {
			$removed += isset( $sourced[ $table ] )
				? $this->deleteInBatches( $table, 'date < %s AND source = %s', [ $cutoff, ImportSource::NATIVE ] )
				: $this->deleteInBatches( $table, 'date < %s', [ $cutoff ] );
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
	 * Delete share links that have been revoked or expired for a month.
	 *
	 * Not swept the moment they lapse: an administrator who just revoked a
	 * link, or watched one expire, should still be able to see it listed as
	 * revoked or expired for a while rather than have it vanish outright.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteExpiredShareLinks( int $now ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );

		return $this->deleteInBatches(
			Tables::SHARE_LINKS,
			'(revokedAt IS NOT NULL AND revokedAt < %s) OR (expiresAt IS NOT NULL AND expiresAt < %s)',
			[ $cutoff, $cutoff ]
		);
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

		$removed = 0;

		// Looped, like every other sweep here. A single chunked DELETE removed
		// five thousand rows a night whatever had accumulated, so a site that
		// orphaned two hundred thousand sessions - one crash during a busy
		// afternoon - would have taken forty days to clear them, with the
		// backlog sitting at the front of the idle query the whole time.
		while ( true ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$deleted = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM `$table` WHERE lastSeenAt < %d LIMIT %d", $cutoff, self::DELETE_CHUNK )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$removed += $deleted;

			if ( $deleted < self::DELETE_CHUNK ) {
				return $removed;
			}
		}
	}

	/**
	 * Delete dimensions nothing references any more.
	 *
	 * Walked by primary key in chunks rather than by building one enormous NOT
	 * IN list - which is exactly the shape that falls over on the cardinality
	 * spike this exists to clean up after.
	 *
	 * @param int $now Timestamp.
	 */
	private function deleteOrphanedDimensions( int $now ): int {
		// Weekly, not nightly. This asks each of the twenty-four columns that
		// point at the dimensions table which of a thousand candidate ids it
		// still references, once per thousand - so a hundred thousand
		// dimensions is 2,400 queries, and most of those columns lead no
		// index. On a site that has taken a cardinality spike, which is
		// precisely what this exists to clean up after, it is the most
		// expensive thing the tidy-up does and the least urgent: an
		// unreferenced dimension row is a few dozen bytes and harms nothing
		// while it waits.
		//
		// Not inverted into one `SELECT DISTINCT` per table diffed in PHP,
		// which is the other obvious shape. That would widen the gap between
		// deciding a dimension is unused and deleting it, and a dimension
		// deleted while a drain is referencing it takes the label every rollup
		// row is read through with it - permanently, because no backup of the
		// aggregates can put a name back. Cheaper is not worth looser here.
		$last = (int) get_option( self::ORPHAN_SWEEP_OPTION, 0 );

		if ( $last > 0 && ( $now - $last ) < WEEK_IN_SECONDS ) {
			return 0;
		}

		update_option( self::ORPHAN_SWEEP_OPTION, $now, false );

		try {
			return $this->sweepOrphanedDimensions();
		} catch ( \Throwable $e ) {
			// Stop rather than guess. Everything swept before the failure is
			// already gone and was genuinely unreferenced; what is left stays
			// until a run that can read every reference table.
			Log::error( 'The orphaned-dimension sweep stopped early: ' . $e->getMessage() );

			return 0;
		}
	}

	/**
	 * The sweep itself.
	 */
	private function sweepOrphanedDimensions(): int {
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

				// Db::col(), not get_col(): a failed query and "nothing
				// references these" are the same empty array, and here the
				// difference is whether a thousand live dimensions are
				// deleted. Contention with a running drain is the ordinary
				// way that query fails, and the rows it would take are the
				// labels every rollup row is read through - deleting them
				// blanks the reports permanently and no backup of the
				// aggregates can put a name back.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$used = Db::col(
					$wpdb->prepare(
						"SELECT DISTINCT `$column` FROM `$name` WHERE `$column` IN ($placeholders)",
						$candidates
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

				foreach ( $used as $id ) {
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
