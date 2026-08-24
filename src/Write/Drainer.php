<?php
/**
 * Turning spooled hits into totals.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Capture\CaptureService;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Rollup\Aggregator;
use HonestAnalytics\Rollup\GoalMatcher;
use HonestAnalytics\Rollup\JourneyRecorder;
use HonestAnalytics\Rollup\RollupSinkInterface;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Schema\Upgrader;
use HonestAnalytics\Sessions\SessionDelta;
use HonestAnalytics\Sessions\SessionStoreInterface;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\DbKeyValueStore;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Db;
use HonestAnalytics\Support\Lock;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Losses;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The scheduled job that makes the reports fill up.
 *
 * Crash safety rests on four moves.
 *
 * **Claim by rename.** The live spool is renamed before it is read, so writers
 * carry on appending to a fresh file and this run owns what it took. Anything a
 * previous run claimed but never finished is picked up again by name.
 *
 * **Commit once, marked.** The rollup writes and a row in the drain log share
 * one transaction, so a batch is either fully counted or not counted at all,
 * and a replay can tell which.
 *
 * **Idempotent sessions.** A session records the batch that last touched it, so
 * re-applying a batch changes nothing.
 *
 * **Bounded retries.** Three failures and a batch is set aside rather than
 * retried forever, with a command that puts it back.
 *
 * The lock matters as much as any of them: claim-by-rename says nothing about
 * two runs closing the *same session*, and that would double every
 * session-scoped figure it carries.
 */
final class Drainer {

	private const CHUNK_SEPARATOR = '#';
	private const LOCK_NAME       = 'drain';

	/**
	 * Failures before a queue batch is set aside.
	 *
	 * The same three the file path uses, kept here rather than shared, because
	 * the two quarantines are different mechanisms - a rename on one side, a
	 * stamped batch id on the other - and a single constant would imply they
	 * have to move together.
	 */
	private const QUEUE_MAX_ATTEMPTS = 3;

	/**
	 * Stamped on queue rows that have failed too often.
	 *
	 * Anything but NULL keeps them out of the claim query, which is what makes
	 * this a quarantine rather than a deletion. `wp honest-analytics drain
	 * --retry` clears them the same way it un-quarantines a spool file.
	 */
	public const QUEUE_FAILED_PREFIX = 'failed-';

	/** Expired store rows removed per run - a few minutes' worth on a busy site. */
	private const STORE_SWEEP_ROWS = 20000;

	/**
	 * Idle sessions committed per transaction.
	 *
	 * Small enough that the row locks it takes across the rollup tables are
	 * held for a moment rather than for the length of the whole sweep, and
	 * large enough that the per-transaction overhead stays negligible.
	 */
	private const CLOSE_CHUNK = 250;

	/** Hits materialised at once. A full spool would otherwise exhaust memory. */
	public int $chunkHits = 20000;

	/**
	 * The file half of the job: claiming, reading and setting aside batches.
	 */
	private SpoolBatchReader $files;

	public function __construct(
		private Settings $settings,
		private SessionStoreInterface $sessions,
		private RollupSinkInterface $sink,
		private GoalMatcher $matcher,
		private JourneyRecorder $journeys
	) {
		$this->files = new SpoolBatchReader( $this->chunkHits );
	}

	/**
	 * When this run must stop, or 0.0 when it may take as long as it needs.
	 */
	private float $deadline = 0.0;

	/**
	 * Whether this run has used its budget.
	 */
	private function outOfTime(): bool {
		return $this->deadline > 0.0 && microtime( true ) >= $this->deadline;
	}

	/**
	 * Run a drain.
	 *
	 * A budget of zero means "finish the job", which is what cron and the CLI
	 * want. A budget in seconds means "do what you can and stop", which is what
	 * an ordinary page request wants: a site with no cron at all drains from
	 * its own traffic, and no single visitor should ever pay for the whole
	 * backlog. Work left behind stays claimed and is picked up by the next run,
	 * so stopping early costs nothing but time.
	 *
	 * @param int|null $now     Timestamp, defaulting to now.
	 * @param float    $seconds How long this run may take, or 0 for no limit.
	 */
	public function run( ?int $now = null, float $seconds = 0.0 ): DrainResult {
		$result = new DrainResult();
		$now    = $now ?? time();
		$start  = microtime( true );

		$this->deadline = $seconds > 0 ? $start + $seconds : 0.0;

		// A stale schema means the rollup tables are missing a column this
		// build writes, and every batch would fail on `Unknown column`, be
		// retried three times and quarantined - for every batch, while the
		// spool climbs to its ceiling and SpoolWriter starts dropping hits.
		// Standing down leaves the spool exactly as it is, to be drained by the
		// run that follows the migration. Nothing is lost by waiting; a great
		// deal is lost by not.
		if ( ! Upgrader::isCurrent() ) {
			return $result;
		}

		$lock = new Lock( self::LOCK_NAME );

		// Declining is the right answer for the loser. Another drain is already
		// doing this work.
		if ( ! $lock->acquire( 0 ) ) {
			return $result;
		}

		try {
			foreach ( $this->files->claimFiles() as $file ) {
				if ( $this->outOfTime() ) {
					break;
				}

				$this->processFile( $file, $result );
			}

			$this->processQueuedRows( $result );
			$this->closeIdleSessions( $now, $result );
			$this->sweepStore();

			// Added, not assigned: processQueuedRows() may already have counted
			// a database batch it set aside, and an assignment here would
			// silently forget it.
			$result->quarantinedBatches += count( $this->files->failedFiles() );
		} finally {
			$lock->release();
		}

		$result->seconds = microtime( true ) - $start;

		$this->recordRun( $result, $now );

		return $result;
	}

	/**
	 * Put quarantined batches back in the queue.
	 *
	 * The name is kept up to the suffix, so a retried batch presents the same
	 * identity to the drain log and cannot double-count if it had partly
	 * committed.
	 *
	 * @return int Batches requeued.
	 */
	public function retryFailed(): int {
		global $wpdb;

		$count = $this->files->requeueFailed();

		// And the database queue's equivalent. Both drivers set batches aside
		// after repeated failures, so both have to be reachable from the one
		// button and the one command - otherwise a site on the queue driver is
		// told batches were quarantined and offered no way to put them back.
		$table = Tables::name( Tables::SPOOL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$released = (int) $wpdb->query(
			$wpdb->prepare( "UPDATE `$table` SET batchId = NULL WHERE batchId LIKE %s", $wpdb->esc_like( self::QUEUE_FAILED_PREFIX ) . '%' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count + ( $released > 0 ? 1 : 0 );
	}

	/**
	 * Process one claimed file.
	 *
	 * @param string      $file   File path.
	 * @param DrainResult $result Result, updated in place.
	 */
	private function processFile( string $file, DrainResult $result ): void {
		$batchId = basename( $file, SpoolBatchReader::CLAIMED_SUFFIX );

		if ( $this->isCommitted( $batchId ) ) {
			$this->files->discard( $file );
			++$result->skippedBatches;

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $file, 'rb' );

		if ( false === $handle ) {
			Log::warning( 'Could not open a claimed spool file.' );
			Losses::record( Losses::UNREADABLE );

			return;
		}

		// Where the last run stopped, if it stopped part way. Without this a
		// resumed file was decoded from byte zero every time: readChunk()
		// materialised up to 20,000 Hit objects per already-committed chunk
		// purely to discover the marker said skip, and the skip path had no
		// outOfTime() check to stop it. A 50 MB spool drained in five-second
		// bites could spend every run re-reading the same prefix and never
		// reach the end.
		//
		// The offset cannot be inferred by counting lines: readChunk() counts
		// *usable* hits toward a chunk and consumes malformed lines on top, so
		// a chunk boundary depends on what decoding found. It is recorded in
		// the key-value store instead, where losing it - an evicted cache, a
		// swept row - simply means falling back to the old behaviour of
		// re-reading, which is slow rather than wrong.
		$chunkIndex = $this->files->resume( $handle, $batchId );

		try {
			while ( true ) {
				[ $hits, $malformed ] = $this->files->readChunk( $handle );

				$result->malformedLines += $malformed;

				if ( [] === $hits ) {
					break;
				}

				$chunkId = $batchId . self::CHUNK_SEPARATOR . $chunkIndex;
				++$chunkIndex;

				if ( $this->isCommitted( $chunkId ) ) {
					$this->files->rememberProgress( $handle, $batchId, $chunkIndex );

					if ( $this->outOfTime() ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $handle );

						return;
					}

					continue;
				}

				$applied = $this->applyChunk( $chunkId, $hits, $result );

				if ( ! $applied ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );

					$this->files->recordFailure( $file, $result );

					return;
				}

				$result->hits += count( $hits );

				$this->files->rememberProgress( $handle, $batchId, $chunkIndex );

				if ( $this->outOfTime() ) {
					// Mid-file. The chunks already applied carry their own
					// markers, and the offset recorded a line ago says where to
					// pick up, so the next run resumes here rather than walking
					// the file again to find the same place.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );

					return;
				}
			}

			// The whole-file marker goes last, so a replay skips the file
			// outright rather than walking chunks it has already committed.
			$this->commit(
				$batchId,
				static function (): void {
				}
			);
		} finally {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
			}
		}

		$this->files->discard( $file );
		++$result->batches;
	}

	/**
	 * Apply one chunk of hits.
	 *
	 * @param string      $chunkId Chunk identifier.
	 * @param Hit[]       $hits    Hits.
	 * @param DrainResult $result  Result, updated in place.
	 */
	private function applyChunk( string $chunkId, array $hits, DrainResult $result ): bool {
		$acquisition = $this->acquisitionReferrers( $hits );
		$aggregator  = new Aggregator( $this->settings );

		foreach ( $hits as $hit ) {
			$key = SessionDelta::key( $hit->siteId, $hit->sessionKey );

			$aggregator->add( $hit, $acquisition[ $key ] ?? $hit->referrer );
		}

		$deltas = $this->deltas( $hits );

		// Matched here because this is the last moment the paths and event names
		// exist. After this they are counters.
		$this->matcher->matchBatch( $hits, $deltas );

		$result->buckets += $aggregator->bucketCount();

		return $this->commit(
			$chunkId,
			function () use ( $deltas, $chunkId, $aggregator, $hits ): void {
				// Inside the transaction with the counters, so a batch that
				// rolls back leaves its sessions where they were rather than
				// advanced past views that were never counted.
				$this->sessions->applyBatch( $deltas, $chunkId );

				$this->sink->flush( $aggregator->buckets(), [], $aggregator->interactions );
				$this->journeys->record( $hits );
			}
		);
	}

	/**
	 * The referrer each session arrived by.
	 *
	 * One read per distinct session rather than per hit.
	 *
	 * @param Hit[] $hits Hits.
	 *
	 * @return array<string,string>
	 */
	private function acquisitionReferrers( array $hits ): array {
		// Fetched in one go rather than one at a time. This asked the store for
		// every distinct session in the chunk individually, and applyBatch()
		// then asked for the same ones again - two queries per session per
		// chunk on the database store, to answer a question one `IN (...)`
		// covers.
		$wanted = [];

		foreach ( $hits as $hit ) {
			$wanted[ $hit->siteId ][ $hit->sessionKey ] = true;
		}

		$known = [];

		foreach ( $wanted as $siteId => $keys ) {
			$known[ $siteId ] = $this->sessions->getMany( (int) $siteId, array_keys( $keys ) );
		}

		$out = [];

		foreach ( $hits as $hit ) {
			$key = SessionDelta::key( $hit->siteId, $hit->sessionKey );

			if ( array_key_exists( $key, $out ) ) {
				continue;
			}

			$session = $known[ $hit->siteId ][ $hit->sessionKey ] ?? null;

			$out[ $key ] = ( null !== $session && '' !== $session->referrer ) ? $session->referrer : $hit->referrer;
		}

		return $out;
	}

	/**
	 * Group hits into per-session deltas.
	 *
	 * @param Hit[] $hits Hits.
	 *
	 * @return array<string,SessionDelta>
	 */
	private function deltas( array $hits ): array {
		$deltas = [];

		foreach ( $hits as $hit ) {
			if ( Hit::KIND_CRAWLER === $hit->kind ) {
				continue;
			}

			$key = SessionDelta::key( $hit->siteId, $hit->sessionKey );

			if ( ! isset( $deltas[ $key ] ) ) {
				$deltas[ $key ] = SessionDelta::fromHit( $hit );

				continue;
			}

			$deltas[ $key ]->add( $hit );
		}

		return $deltas;
	}

	/**
	 * Run a batch's writes and mark it done, in one transaction.
	 *
	 * The writes go through `Support\Db`, which turns a failed statement into
	 * an exception. Without that, `$wpdb` would answer false, the marker would
	 * still be written, and a batch that was only partly applied - or, after a
	 * deadlock, not applied at all - would be recorded as committed and its
	 * file deleted.
	 *
	 * @param string   $batchId Batch identifier.
	 * @param callable $writes  Everything the batch writes.
	 */
	private function commit( string $batchId, callable $writes ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			$writes();

			Db::insert(
				Tables::name( Tables::DRAIN_LOG ),
				[
					'batchId'     => $batchId,
					'driver'      => $this->settings->writeDriver,
					'committedAt' => gmdate( 'Y-m-d H:i:s' ),
				],
				[ '%s', '%s', '%s' ]
			);

			Db::query( 'COMMIT' );

			return true;
		} catch ( \Throwable $e ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			Log::error( 'Drain batch ' . $batchId . ' failed: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Whether a batch has already been committed.
	 *
	 * @param string $batchId Batch identifier.
	 */
	private function isCommitted( string $batchId ): bool {
		global $wpdb;

		$table = Tables::name( Tables::DRAIN_LOG );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE batchId = %s", $batchId ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drain hits queued in the database.
	 *
	 * @param DrainResult $result Result, updated in place.
	 */
	private function processQueuedRows( DrainResult $result ): void {
		global $wpdb;

		$table = Tables::name( Tables::SPOOL );

		while ( true ) {
			// The budget applies here too. This loop had no check at all, and
			// run() reaches it after the file loop has already stopped for
			// being out of time - so on the database driver, which is what
			// managed hosts with a read-only filesystem use, a visitor's page
			// request drained the entire queue however large it was. docs/cron
			// promises at most five seconds; this could be killed part way
			// through by max_execution_time instead.
			if ( $this->outOfTime() ) {
				return;
			}

			$batchId = 'queue-' . bin2hex( random_bytes( 8 ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$claimed = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE `$table` SET batchId = %s WHERE batchId IS NULL ORDER BY id ASC LIMIT %d",
					$batchId,
					$this->chunkHits
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $claimed <= 0 ) {
				return;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$lines = $wpdb->get_col( $wpdb->prepare( "SELECT line FROM `$table` WHERE batchId = %s ORDER BY id ASC", $batchId ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$hits = [];

			foreach ( (array) $lines as $line ) {
				$hit = Hit::decode( (string) $line );

				if ( null === $hit || $hit->siteId <= 0 || ! SpoolBatchReader::hasUsableIdentity( $hit ) ) {
					++$result->malformedLines;

					continue;
				}

				$hits[] = $hit;
			}

			if ( [] !== $hits && ! $this->applyChunk( $batchId, $hits, $result ) ) {
				++$result->failedBatches;

				// Counted, and set aside once it has failed enough times. The
				// claim used to be released unconditionally, and since the next
				// run claims the same rows in the same order, one row the
				// pipeline could not stomach blocked the queue for ever - while
				// the file path quarantines after three tries and carries on.
				// Every hit behind it waited on it indefinitely.
				//
				// Keyed on the lowest row id rather than the batch id, because
				// a released batch gets a fresh random id on the next attempt
				// and there would be nothing to count against.
				$this->countQueueFailure( $table, $batchId, $result );

				return;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE batchId = %s", $batchId ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$result->hits += count( $hits );
			++$result->batches;
		}
	}

	/**
	 * Count a failed queue batch, and quarantine it once it has had its three.
	 *
	 * Quarantine here means stamping the rows with a batch id that the claim
	 * query will never match again, which is the database equivalent of the
	 * file path's `.failed` rename: the rows stay, they can be looked at, and
	 * they stop holding up everything queued behind them.
	 *
	 * @param string      $table   Prefixed spool table name.
	 * @param string      $batchId The batch that failed.
	 * @param DrainResult $result  Result, updated in place.
	 */
	private function countQueueFailure( string $table, string $batchId, DrainResult $result ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$lowest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM `$table` WHERE batchId = %s", $batchId ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$store    = StoreFactory::keyValue();
		$key      = 'queue-attempts:' . $lowest;
		$attempts = (int) $store->get( $key ) + 1;

		if ( $attempts >= self::QUEUE_MAX_ATTEMPTS ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET batchId = %s WHERE batchId = %s", self::QUEUE_FAILED_PREFIX . $lowest, $batchId ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$store->delete( $key );

			++$result->quarantinedBatches;

			Losses::record( Losses::QUARANTINED );

			Log::warning( 'A queued batch failed repeatedly and has been set aside from row ' . $lowest . '.' );

			return;
		}

		$store->set( $key, $attempts, DAY_IN_SECONDS );

		// Released, so the next run tries again.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET batchId = NULL WHERE batchId = %s", $batchId ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Commit and remove visits that have gone quiet.
	 *
	 * Staged first, so a crash between committing and deleting leaves a session
	 * marked with a batch id that can be looked up rather than counted twice.
	 *
	 * @param int         $now    Timestamp.
	 * @param DrainResult $result Result, updated in place.
	 */
	public function closeIdleSessions( int $now, DrainResult $result ): void {
		try {
			$stale = [];
			$due   = [];

			foreach ( $this->sessions->idleSessions( $now ) as $session ) {
				if ( null !== $session->closedByBatch && $this->isCommitted( $session->closedByBatch ) ) {
					// Already counted; the crash was between the commit and
					// the delete.
					$stale[] = $session;

					continue;
				}

				// Either never staged, or staged by a run whose commit rolled
				// back or never happened - in which case nothing was counted
				// and it is simply closed again under this batch. Leaving such
				// a session alone is not an option: the idle query returns the
				// oldest first, so the stuck ones would sit at the front of it
				// for ever, and once enough had gathered no live session would
				// reach the drain again.
				$due[] = $session;
			}

			$this->sessions->deleteMany( $stale );

			// Chunked, each with its own batch id. One transaction over five
			// thousand sessions holds that many row locks across every table
			// the reports read, for as long as the flush takes. Idempotency
			// already keys off `closedByBatch`, so a marker per chunk is as
			// correct as one for the lot - and a failure now costs one chunk
			// rather than the whole sweep.
			foreach ( array_chunk( $due, self::CLOSE_CHUNK ) as $chunk ) {
				$batchId = 'idle-' . bin2hex( random_bytes( 8 ) );

				foreach ( $chunk as $session ) {
					$session->closedByBatch = $batchId;
				}

				// Staged through saveMany() rather than one save() per session:
				// the cache store rewrites the whole site index on every save,
				// so this loop was the drain sending a megabyte to Redis a few
				// thousand times over and never finishing.
				$this->sessions->saveMany( $chunk );

				if ( ! $this->commit( $batchId, fn () => $this->sink->flush( [], $chunk ) ) ) {
					++$result->failedBatches;

					return;
				}

				$this->sessions->deleteMany( $chunk );

				$result->closedSessions += count( $chunk );

				if ( $this->outOfTime() ) {
					// The rest stay staged and are picked up next run. They
					// carry no batch id yet, so nothing has been counted for
					// them and nothing will be counted twice.
					return;
				}
			}
		} catch ( \Throwable $e ) {
			++$result->failedBatches;

			Log::error( 'Closing idle sessions failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Clear expired nonces and rate-limit windows from the table store.
	 *
	 * Done here because the drain is the one job that runs every few minutes
	 * on every site, with or without cron. The nightly tidy-up sweeps too,
	 * but a day of expired rows on a busy site is millions of them, and the
	 * table they sit in is the one the capture path writes to on every
	 * request. A bounded sweep each run keeps it small instead.
	 */
	private function sweepStore(): void {
		if ( $this->outOfTime() ) {
			return;
		}

		$store = StoreFactory::keyValue();

		if ( ! $store instanceof DbKeyValueStore ) {
			return;
		}

		try {
			$store->sweep( self::STORE_SWEEP_ROWS );
		} catch ( \Throwable $e ) {
			Log::warning( 'Could not sweep the key-value store: ' . $e->getMessage() );
		}
	}

	/**
	 * Record what this run did, for the health check.
	 *
	 * @param DrainResult $result Result.
	 * @param int         $now    Timestamp.
	 */
	private function recordRun( DrainResult $result, int $now ): void {
		update_option(
			'honest_analytics_last_drain',
			array_merge( $result->toArray(), [ 'at' => $now ] ),
			false
		);
	}
}
