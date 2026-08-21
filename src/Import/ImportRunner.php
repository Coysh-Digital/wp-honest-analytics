<?php
/**
 * The batch loop.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one slice of one import, then decides what happens next.
 *
 * The loop is deliberately small and the state is deliberately all in the
 * database. Nothing is held between batches in memory, in a transient or in a
 * static, because the next batch may run in a different process an hour later
 * on a machine that has been restarted since. What survives is the job row, and
 * the job row is enough.
 *
 * **A batch that dies leaves the cursor at the last completed day.** The
 * importer advances its own cursor only when a day has been handed over and
 * written, so a PHP timeout halfway through 14 March means 14 March is imported
 * again from the top - and since the sink replaces a day rather than adding to
 * it, importing it again is free of consequence. That is the whole reason the
 * sink works the way it does.
 */
final class ImportRunner {

	/**
	 * How long a batch may run before it stops asking for more.
	 *
	 * Well under the default PHP limit, because the batch has to finish and
	 * write its progress rather than be killed mid-write. A cron tick that
	 * spends nine seconds and returns is worth more than one that spends thirty
	 * and gets shot.
	 */
	private const BATCH_SECONDS = 8;

	/** After this many consecutive failures the job stops and says so. */
	private const MAX_ATTEMPTS = 5;

	/** Backoff between failed attempts, in seconds, by attempt number. */
	private const BACKOFF = [ 30, 120, 300, 900, 3600 ];

	private ImportRepository $repository;
	private Coverage $coverage;
	private ImportSink $sink;

	/**
	 * @param ImportRepository|null $repository Job storage.
	 * @param Coverage|null         $coverage   Per-day coverage.
	 * @param ImportSink|null       $sink       Where days are written.
	 */
	public function __construct( ?ImportRepository $repository = null, ?Coverage $coverage = null, ?ImportSink $sink = null ) {
		$this->repository = $repository ?? new ImportRepository();
		$this->coverage   = $coverage ?? new Coverage();
		$this->sink       = $sink ?? new ImportSink( Plugin::instance()->capper() );
	}

	/**
	 * Begin an import.
	 *
	 * @param int                 $siteId        Site.
	 * @param string              $source        Importer id.
	 * @param ImportConfiguration $configuration What the user chose.
	 *
	 * @throws \RuntimeException When the source is unknown or already running.
	 */
	public function begin( int $siteId, string $source, ImportConfiguration $configuration ): ImportJob {
		$importer = ImporterRegistry::get( $source );

		if ( null === $importer ) {
			throw new \RuntimeException( 'Unknown import source.' );
		}

		$existing = $this->repository->latestFor( $siteId, $source );

		if ( null !== $existing && $existing->isActive() ) {
			return $existing;
		}

		$cursor = $importer->start( $configuration );
		$days   = Coverage::dayCount( $configuration->dateFrom, $configuration->dateTo );

		$job = $this->repository->create( $siteId, $source, $configuration, $cursor, $days );

		ImportLog::write(
			$job->id,
			ImportLog::INFO,
			'Import started.',
			[
				'source'  => $source,
				'from'    => $configuration->dateFrom,
				'to'      => $configuration->dateTo,
				'days'    => $days,
				'overlap' => $configuration->overlap,
			]
		);

		return $job;
	}

	/**
	 * Run one batch of one job.
	 *
	 * @param ImportJob $job The job.
	 */
	public function runBatch( ImportJob $job ): ImportJob {
		$importer = ImporterRegistry::get( $job->source );

		if ( null === $importer ) {
			return $this->fail( $job, 'This import source is no longer available on this site.', 'importer missing: ' . $job->source );
		}

		if ( ! $job->isActive() ) {
			return $job;
		}

		$job->status = ImportJob::STATUS_RUNNING;
		$this->repository->save( $job );

		$started = microtime( true );
		$clashes = $this->coverage->clashes( $job->siteId, $job->source, $job->configuration->dateFrom, $job->configuration->dateTo );
		$skipped = 0;
		$written = 0;

		$writer = function ( DayBucket $bucket ) use ( $job, $clashes, &$skipped, &$written ): int {
			return $this->writeDay( $job, $bucket, $clashes, $skipped, $written );
		};

		try {
			$result = $importer->processBatch( $job, $writer );
		} catch ( \Throwable $e ) {
			return $this->fail( $job, $this->friendlyError( $job->source ), get_class( $e ) . ': ' . $e->getMessage() );
		}

		$job->cursor            = [] !== $result->cursor ? $result->cursor : $job->cursor;
		$job->recordsProcessed += $result->processed;
		$job->recordsImported  += $written;
		$job->recordsSkipped   += $result->skipped + $skipped;
		$job->daysDone          = min( $job->daysTotal, $job->daysDone + $result->daysDone );
		$job->attempts          = 0;
		$job->lastError         = '';

		ImportLog::write(
			$job->id,
			ImportLog::DEBUG,
			'Batch finished.',
			[
				'days'     => $result->daysDone,
				'read'     => $result->processed,
				'written'  => $written,
				'skipped'  => $result->skipped + $skipped,
				'seconds'  => round( microtime( true ) - $started, 2 ),
				'cursor'   => $job->cursor,
				'progress' => $job->percent(),
			]
		);

		if ( $result->finished ) {
			return $this->complete( $job, $importer );
		}

		if ( $result->retryAfter > 0 ) {
			$job->status     = ImportJob::STATUS_WAITING;
			$job->retryAfter = time() + $result->retryAfter;
			$job->lastError  = $result->message;

			ImportLog::write(
				$job->id,
				ImportLog::INFO,
				'Paused, and will continue by itself.',
				[
					'seconds'   => $result->retryAfter,
					'technical' => $result->technical,
				]
			);

			$this->repository->save( $job );

			return $job;
		}

		$job->status = ImportJob::STATUS_RUNNING;
		$this->repository->save( $job );

		return $job;
	}

	/**
	 * How long a batch is allowed to keep going.
	 *
	 * Importers ask this rather than counting rows, so that a slow shared host
	 * does less per batch and a fast one does more, without either needing to be
	 * configured.
	 */
	public static function batchSeconds(): int {
		/**
		 * Filters how long one import batch may run.
		 *
		 * @param int $seconds Seconds.
		 */
		return max( 2, (int) apply_filters( 'honest_analytics_import_batch_seconds', self::BATCH_SECONDS ) );
	}

	/**
	 * Stop a job at the user's request.
	 *
	 * Nothing already imported is removed. A cancelled import is a shorter
	 * import, not an undone one, and the days it did finish are real.
	 *
	 * @param ImportJob $job The job.
	 */
	public function cancel( ImportJob $job ): ImportJob {
		if ( ! $job->isActive() ) {
			return $job;
		}

		$job->status      = ImportJob::STATUS_CANCELLED;
		$job->completedAt = time();

		$this->repository->save( $job );

		$importer = ImporterRegistry::get( $job->source );

		if ( null !== $importer ) {
			$importer->cleanUp( $job );
		}

		ImportLog::write( $job->id, ImportLog::INFO, 'Import stopped by the user.', [ 'daysDone' => $job->daysDone ] );

		return $job;
	}

	/**
	 * Write one day, honouring the overlap choice.
	 *
	 * @param ImportJob            $job     The job.
	 * @param DayBucket            $bucket  The day.
	 * @param array<string,string> $clashes Days another source already owns.
	 * @param int                  $skipped Running skip count, by reference.
	 * @param int                  $written Running write count, by reference.
	 */
	private function writeDay( ImportJob $job, DayBucket $bucket, array $clashes, int &$skipped, int &$written ): int {
		$owner = $clashes[ $bucket->date ] ?? null;

		if ( null !== $owner && ImportConfiguration::OVERLAP_SKIP === $job->configuration->overlap ) {
			// The safe default, and the reason it is the default: adding this
			// day on top of another source's would double every figure in it,
			// and nobody would be able to tell afterwards which half was which.
			++$skipped;

			return 0;
		}

		if ( null !== $owner ) {
			// Replace: the other source's day goes, rows and coverage together,
			// so that the record of who owns the day follows the data.
			$this->sink->clearDay( $job->siteId, $owner, $bucket->date );
			$this->coverage->forget( $job->siteId, $owner, $bucket->date );
		}

		if ( $bucket->isEmpty() ) {
			// A day with no traffic is still a day that has been imported.
			// Recording it stops a second run offering to fetch it again.
			$this->coverage->record( $job->siteId, $job->source, $job->id, $bucket->date, 0 );

			return 0;
		}

		$rows = $this->sink->write( $job->siteId, $job->source, $bucket );

		$this->coverage->record( $job->siteId, $job->source, $job->id, $bucket->date, $rows );

		$written += $rows;

		return $rows;
	}

	/**
	 * Mark a job done.
	 *
	 * @param ImportJob         $job      The job.
	 * @param ImporterInterface $importer Its importer.
	 */
	private function complete( ImportJob $job, ImporterInterface $importer ): ImportJob {
		$job->status      = ImportJob::STATUS_COMPLETE;
		$job->completedAt = time();
		$job->daysDone    = $job->daysTotal;
		$job->retryAfter  = 0;

		$this->repository->save( $job );

		try {
			$importer->cleanUp( $job );
		} catch ( \Throwable $e ) {
			ImportLog::write( $job->id, ImportLog::WARNING, 'Tidying up after the import did not finish cleanly.', [ 'technical' => $e->getMessage() ] );
		}

		ImportLog::write(
			$job->id,
			ImportLog::INFO,
			'Import complete.',
			[
				'days'     => $job->daysDone,
				'imported' => $job->recordsImported,
				'skipped'  => $job->recordsSkipped,
				'seconds'  => max( 0, $job->completedAt - $job->startedAt ),
			]
		);

		return $job;
	}

	/**
	 * Record a failure and decide whether to try again.
	 *
	 * @param ImportJob $job       The job.
	 * @param string    $message   What to tell the user.
	 * @param string    $technical What to write in the log.
	 */
	private function fail( ImportJob $job, string $message, string $technical ): ImportJob {
		++$job->attempts;

		$job->lastError = $message;

		if ( $job->attempts >= self::MAX_ATTEMPTS ) {
			$job->status      = ImportJob::STATUS_FAILED;
			$job->completedAt = time();

			ImportLog::write(
				$job->id,
				ImportLog::ERROR,
				'Import stopped after repeated failures.',
				[
					'attempts'  => $job->attempts,
					'technical' => $technical,
				]
			);
		} else {
			$job->status     = ImportJob::STATUS_WAITING;
			$job->retryAfter = time() + ( self::BACKOFF[ $job->attempts - 1 ] ?? 3600 );

			ImportLog::write(
				$job->id,
				ImportLog::WARNING,
				'A batch did not finish; it will be tried again.',
				[
					'attempt'   => $job->attempts,
					'technical' => $technical,
				]
			);
		}

		$this->repository->save( $job );

		return $job;
	}

	/**
	 * What to tell somebody when a batch throws.
	 *
	 * Never the exception. "SQLSTATE HY000" is not a sentence, and a person
	 * reading it learns nothing they can act on. The detail is in the log.
	 *
	 * @param string $source Importer id.
	 */
	private function friendlyError( string $source ): string {
		return sprintf(
			/* translators: %s: the name of the analytics tool being imported from. */
			__( 'We could not continue the %s import just now. Your progress has been saved and we will try again shortly.', 'honest-analytics' ),
			ImportSource::label( $source )
		);
	}

	/**
	 * The repository this runner uses.
	 */
	public function repository(): ImportRepository {
		return $this->repository;
	}

	/**
	 * The coverage this runner uses.
	 */
	public function coverage(): Coverage {
		return $this->coverage;
	}
}
