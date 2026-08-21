<?php
/**
 * What a drain did.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The report a drain hands back.
 *
 * Every counter here is on the CLI output, and a drain that failed anything
 * exits non-zero. A drain that silently succeeds while dropping batches is how
 * this goes unnoticed for weeks.
 */
final class DrainResult {

	public int $batches            = 0;
	public int $skippedBatches     = 0;
	public int $hits               = 0;
	public int $buckets            = 0;
	public int $closedSessions     = 0;
	public int $malformedLines     = 0;
	public int $failedBatches      = 0;
	public int $quarantinedBatches = 0;
	public float $seconds          = 0.0;

	/**
	 * Whether anything went wrong.
	 */
	public function hasFailures(): bool {
		return $this->failedBatches > 0 || $this->quarantinedBatches > 0;
	}

	/**
	 * As an array, for the CLI and the diagnostics screen.
	 *
	 * @return array<string,int|float>
	 */
	public function toArray(): array {
		return [
			'batches'            => $this->batches,
			'skippedBatches'     => $this->skippedBatches,
			'hits'               => $this->hits,
			'buckets'            => $this->buckets,
			'closedSessions'     => $this->closedSessions,
			'malformedLines'     => $this->malformedLines,
			'failedBatches'      => $this->failedBatches,
			'quarantinedBatches' => $this->quarantinedBatches,
			'seconds'            => round( $this->seconds, 3 ),
		];
	}
}
