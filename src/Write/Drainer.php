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
use HonestAnalytics\Sessions\Session;
use HonestAnalytics\Sessions\SessionDelta;
use HonestAnalytics\Sessions\SessionStoreInterface;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Lock;
use HonestAnalytics\Support\Log;
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

	private const CLAIMED_SUFFIX  = '.processing';
	private const FAILED_SUFFIX   = '.failed';
	private const ATTEMPTS_SUFFIX = '.attempts';
	private const MAX_ATTEMPTS    = 3;
	private const CHUNK_SEPARATOR = '#';
	private const LOCK_NAME       = 'drain';

	/** Hits materialised at once. A full spool would otherwise exhaust memory. */
	public int $chunkHits = 20000;

	public function __construct(
		private Settings $settings,
		private SessionStoreInterface $sessions,
		private RollupSinkInterface $sink,
		private GoalMatcher $matcher,
		private JourneyRecorder $journeys
	) {
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

		$lock = new Lock( self::LOCK_NAME );

		// Declining is the right answer for the loser. Another drain is already
		// doing this work.
		if ( ! $lock->acquire( 0 ) ) {
			return $result;
		}

		try {
			foreach ( $this->claimFiles() as $file ) {
				if ( $this->outOfTime() ) {
					break;
				}

				$this->processFile( $file, $result );
			}

			$this->processQueuedRows( $result );
			$this->closeIdleSessions( $now, $result );

			$result->quarantinedBatches = count( $this->failedFiles() );
		} finally {
			$lock->release();
		}

		$result->seconds = microtime( true ) - $start;

		$this->recordRun( $result, $now );

		return $result;
	}

	/**
	 * Take ownership of whatever is waiting.
	 *
	 * @return string[]
	 */
	private function claimFiles(): array {
		$live = Paths::spoolFile();

		if ( is_file( $live ) && filesize( $live ) > 0 ) {
			$claimed = Paths::spoolDir() . '/spool-' . bin2hex( random_bytes( 8 ) ) . self::CLAIMED_SUFFIX;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@rename( $live, $claimed );
		}

		$files = glob( Paths::spoolDir() . '/*' . self::CLAIMED_SUFFIX );

		if ( ! is_array( $files ) ) {
			return [];
		}

		sort( $files );

		return $files;
	}

	/**
	 * Files set aside after repeated failures.
	 *
	 * @return string[]
	 */
	public function failedFiles(): array {
		$files = glob( Paths::spoolDir() . '/*' . self::FAILED_SUFFIX );

		return is_array( $files ) ? $files : [];
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
		$count = 0;

		foreach ( $this->failedFiles() as $file ) {
			$target = substr( $file, 0, -strlen( self::FAILED_SUFFIX ) ) . self::CLAIMED_SUFFIX;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $file, $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file . self::ATTEMPTS_SUFFIX );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Process one claimed file.
	 *
	 * @param string      $file   File path.
	 * @param DrainResult $result Result, updated in place.
	 */
	private function processFile( string $file, DrainResult $result ): void {
		$batchId = basename( $file, self::CLAIMED_SUFFIX );

		if ( $this->isCommitted( $batchId ) ) {
			$this->discard( $file );
			++$result->skippedBatches;

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $file, 'rb' );

		if ( false === $handle ) {
			Log::warning( 'Could not open a claimed spool file.' );

			return;
		}

		$chunkIndex = 0;

		try {
			while ( true ) {
				[ $hits, $malformed ] = $this->readChunk( $handle );

				$result->malformedLines += $malformed;

				if ( [] === $hits ) {
					break;
				}

				$chunkId = $batchId . self::CHUNK_SEPARATOR . $chunkIndex;
				++$chunkIndex;

				if ( $this->isCommitted( $chunkId ) ) {
					continue;
				}

				$applied = $this->applyChunk( $chunkId, $hits, $result );

				if ( ! $applied ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );

					$this->recordFailure( $file, $result );

					return;
				}

				$result->hits += count( $hits );

				if ( $this->outOfTime() ) {
					// Mid-file. The chunks already applied carry their own
					// markers, so the next run skips them and carries on from
					// here rather than starting the file again.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $handle );

					return;
				}
			}

			// The whole-file marker goes last, so a replay skips the file
			// outright rather than walking chunks it has already committed.
			$this->commit( $batchId, [], [], [], null );
		} finally {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
			}
		}

		$this->discard( $file );
		++$result->batches;
	}

	/**
	 * Read up to one chunk of usable hits.
	 *
	 * @param resource $handle Open file handle.
	 *
	 * @return array{0:Hit[],1:int}
	 */
	private function readChunk( $handle ): array {
		$hits      = [];
		$malformed = 0;

		while ( count( $hits ) < $this->chunkHits ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$hit = Hit::decode( $line );

			if ( null === $hit || $hit->siteId <= 0 || ! self::hasUsableIdentity( $hit ) ) {
				++$malformed;

				continue;
			}

			$hits[] = $hit;
		}

		return [ $hits, $malformed ];
	}

	/**
	 * Whether a hit carries an identity the pipeline can use.
	 *
	 * Crawlers carry a deliberate sentinel rather than a hash, so demanding hex
	 * of everything would drop every crawler record as malformed.
	 *
	 * @param Hit $hit The hit.
	 */
	private static function hasUsableIdentity( Hit $hit ): bool {
		if ( Hit::KIND_CRAWLER === $hit->kind ) {
			return CaptureService::CRAWLER_HASH === $hit->visitorHash;
		}

		return IdentityService::isValidHash( $hit->visitorHash );
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

		foreach ( $deltas as $delta ) {
			$this->sessions->apply( $delta, $chunkId );
		}

		$result->buckets += $aggregator->bucketCount();

		return $this->commit( $chunkId, $aggregator->buckets(), [], $hits, $aggregator->interactions );
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
		$out = [];

		foreach ( $hits as $hit ) {
			$key = SessionDelta::key( $hit->siteId, $hit->sessionKey );

			if ( array_key_exists( $key, $out ) ) {
				continue;
			}

			$session = $this->sessions->get( $hit->siteId, $hit->sessionKey );

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
	 * Commit a batch and mark it done, in one transaction.
	 *
	 * @param string                                           $batchId      Batch identifier.
	 * @param array<string,\HonestAnalytics\Rollup\PageBucket> $buckets      Page buckets.
	 * @param Session[]                                        $closed       Sessions being closed.
	 * @param Hit[]                                            $hits         Hits, for the journeys layer.
	 * @param \HonestAnalytics\Rollup\InteractionBuckets|null  $interactions Interactions.
	 */
	private function commit( string $batchId, array $buckets, array $closed, array $hits = [], $interactions = null ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query( 'START TRANSACTION' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			$this->sink->flush( $buckets, $closed, $interactions );

			if ( [] !== $hits ) {
				$this->journeys->record( $hits );
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->insert(
				Tables::name( Tables::DRAIN_LOG ),
				[
					'batchId'     => $batchId,
					'driver'      => $this->settings->writeDriver,
					'committedAt' => gmdate( 'Y-m-d H:i:s' ),
				],
				[ '%s', '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( 'COMMIT' );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	 * Count a failure, and quarantine after enough of them.
	 *
	 * @param string      $file   File path.
	 * @param DrainResult $result Result, updated in place.
	 */
	private function recordFailure( string $file, DrainResult $result ): void {
		++$result->failedBatches;

		$attempts = $this->countAttempt( $file );

		if ( $attempts < self::MAX_ATTEMPTS ) {
			Log::error( 'A drain batch failed and will be retried: ' . basename( $file ) );

			return;
		}

		$target = substr( $file, 0, -strlen( self::CLAIMED_SUFFIX ) ) . self::FAILED_SUFFIX;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		@rename( $file, $target );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file . self::ATTEMPTS_SUFFIX );

		Log::error( 'A drain batch has been set aside after three attempts. Run: wp honest-analytics drain --retry' );
	}

	/**
	 * Increment and read a batch's attempt count.
	 *
	 * Kept in a sidecar file rather than in the name, because the name *is* the
	 * batch identity and renaming it would defeat the commit marker.
	 *
	 * @param string $file File path.
	 */
	private function countAttempt( string $file ): int {
		$path = $file . self::ATTEMPTS_SUFFIX;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		// A failure-count file. If it cannot be read or written the drain
		// carries on regardless — losing the count is not worth an exception,
		// and the quarantine threshold is a heuristic, not a guarantee.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
		$current = is_file( $path ) ? (int) @file_get_contents( $path ) : 0;
		$next    = $current + 1;

		@file_put_contents( $path, (string) $next );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

		return $next;
	}

	/**
	 * Remove a finished batch.
	 *
	 * @param string $file File path.
	 */
	private function discard( string $file ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file . self::ATTEMPTS_SUFFIX );
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

				if ( null === $hit || $hit->siteId <= 0 || ! self::hasUsableIdentity( $hit ) ) {
					++$result->malformedLines;

					continue;
				}

				$hits[] = $hit;
			}

			if ( [] !== $hits && ! $this->applyChunk( $batchId, $hits, $result ) ) {
				++$result->failedBatches;

				// Release the claim so a later run can try again.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
				$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET batchId = NULL WHERE batchId = %s", $batchId ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
			$batchId = 'idle-' . bin2hex( random_bytes( 8 ) );
			$closed  = [];

			foreach ( $this->sessions->idleSessions( $now ) as $session ) {
				if ( null !== $session->closedByBatch ) {
					if ( $this->isCommitted( $session->closedByBatch ) ) {
						// Already counted; the crash was between the commit and
						// the delete.
						$this->sessions->delete( $session->siteId, $session->sessionKey );

						continue;
					}

					continue;
				}

				$session->closedByBatch = $batchId;

				$this->sessions->save( $session );

				$closed[] = $session;
			}

			if ( [] === $closed ) {
				return;
			}

			if ( ! $this->commit( $batchId, [], $closed ) ) {
				++$result->failedBatches;

				return;
			}

			foreach ( $closed as $session ) {
				$this->sessions->delete( $session->siteId, $session->sessionKey );
			}

			$result->closedSessions += count( $closed );
		} catch ( \Throwable $e ) {
			++$result->failedBatches;

			Log::error( 'Closing idle sessions failed: ' . $e->getMessage() );
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
