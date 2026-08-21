<?php
/**
 * A migration in progress, or one that finished.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of the imports table, as an object.
 *
 * Everything needed to pick an import back up lives here and nowhere else: the
 * range, the choices, the cursor, and how far it got. A PHP timeout, a server
 * restart, a cron that never fired, a rate limit - the next batch reads this
 * row and continues, because starting a four-year import again from the
 * beginning is not a recovery strategy.
 */
final class ImportJob {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_WAITING   = 'waiting';
	public const STATUS_COMPLETE  = 'complete';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * @param int                 $id               Row id, 0 before it is saved.
	 * @param int                 $siteId           Site.
	 * @param string              $source           One of the ImportSource constants.
	 * @param string              $status           One of the STATUS_ constants.
	 * @param ImportConfiguration $configuration    What was asked for.
	 * @param array<string,mixed> $cursor           The importer's place.
	 * @param int                 $recordsProcessed Source records read.
	 * @param int                 $recordsImported  Rows written here.
	 * @param int                 $recordsSkipped   Records deliberately not imported.
	 * @param int                 $daysTotal        Days in the requested range.
	 * @param int                 $daysDone         Days finished.
	 * @param int                 $attempts         Consecutive failures.
	 * @param string              $lastError        The last thing to go wrong, for the log.
	 * @param int                 $retryAfter       Unix time before which not to try again.
	 * @param int                 $startedAt        Unix time.
	 * @param int                 $updatedAt        Unix time.
	 * @param int                 $completedAt      Unix time, 0 while running.
	 */
	public function __construct(
		public int $id,
		public readonly int $siteId,
		public readonly string $source,
		public string $status,
		public readonly ImportConfiguration $configuration,
		public array $cursor = [],
		public int $recordsProcessed = 0,
		public int $recordsImported = 0,
		public int $recordsSkipped = 0,
		public int $daysTotal = 0,
		public int $daysDone = 0,
		public int $attempts = 0,
		public string $lastError = '',
		public int $retryAfter = 0,
		public int $startedAt = 0,
		public int $updatedAt = 0,
		public int $completedAt = 0
	) {
	}

	/**
	 * Whether this job still has work to do.
	 */
	public function isActive(): bool {
		return in_array( $this->status, [ self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_WAITING ], true );
	}

	/**
	 * Whether it finished successfully.
	 */
	public function isComplete(): bool {
		return self::STATUS_COMPLETE === $this->status;
	}

	/**
	 * How far along, as a whole percentage.
	 *
	 * Days rather than records, because days are known in advance and records
	 * are not. A progress bar that can only go up is worth more than one that
	 * is briefly more accurate.
	 */
	public function percent(): int {
		if ( $this->isComplete() ) {
			return 100;
		}

		if ( $this->daysTotal <= 0 ) {
			return 0;
		}

		return (int) max( 0, min( 99, floor( $this->daysDone / $this->daysTotal * 100 ) ) );
	}
}
