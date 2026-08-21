<?php
/**
 * What every importer has to be able to do.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One source of historical analytics.
 *
 * An importer reads its own source and fills DayBuckets. It does not touch the
 * rollup tables, does not decide what provenance to write, does not worry about
 * whether a day has been imported before, and never writes to the source it is
 * reading. Those are all the runner's and the sink's problems, deliberately, so
 * that adding a fourth source is a matter of reading a fourth thing rather than
 * getting five shared concerns right for a fourth time.
 *
 * Everything here must be safe to call from an admin page load: `detect()` in
 * particular runs whenever the import screen is opened, so it has to be cheap
 * enough that nobody notices.
 */
interface ImporterInterface {

	/**
	 * The stable identifier, matching an ImportSource constant.
	 */
	public function id(): string;

	/**
	 * The name a person would recognise.
	 */
	public function name(): string;

	/**
	 * Is there anything here?
	 *
	 * Must be cheap, must not modify anything, and must not assume the source
	 * plugin is active - people deactivate the old one and leave its tables
	 * behind, which is exactly the case worth handling well.
	 */
	public function detect(): DetectionResult;

	/**
	 * What would be imported, for the preview screen.
	 *
	 * May be slower than detect(), but not slow: approximate counts clearly
	 * labelled as approximate beat exact counts nobody waited for.
	 */
	public function inspect(): ImportSummary;

	/**
	 * The metric-by-metric mapping, and where it is only approximate.
	 *
	 * @return MetricMapping[]
	 */
	public function mappings(): array;

	/**
	 * Anything that must be said before this particular import starts.
	 *
	 * Shown on the confirmation step, alongside the general note about
	 * different systems counting differently.
	 *
	 * @return string[]
	 */
	public function notes(): array;

	/**
	 * Prepare a job. Called once, before the first batch.
	 *
	 * @param ImportConfiguration $configuration What the user chose.
	 *
	 * @return array<string,mixed> The starting cursor.
	 */
	public function start( ImportConfiguration $configuration ): array;

	/**
	 * Do a slice of the work.
	 *
	 * Must return within a few seconds, must be resumable from the cursor it
	 * returns, and must never leave the source altered. Days are handed to the
	 * sink through the callback rather than written directly, so that
	 * provenance and deduplication stay in one place.
	 *
	 * @param ImportJob $job    The job, including where the last batch got to.
	 * @param callable  $writer function( DayBucket $bucket ): int - returns rows written.
	 */
	public function processBatch( ImportJob $job, callable $writer ): ImportBatchResult;

	/**
	 * Tidy up after a finished or abandoned job.
	 *
	 * Anything held only for the duration of an import - a cached API page, a
	 * temporary token - goes here. Never the source's own data.
	 *
	 * @param ImportJob $job The job.
	 */
	public function cleanUp( ImportJob $job ): void;
}
