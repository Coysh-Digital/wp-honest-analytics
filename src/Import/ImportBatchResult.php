<?php
/**
 * What one batch did.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The importer's answer to "carry on?".
 *
 * A batch either advanced, finished, or could not continue just now. The third
 * is not a failure - a rate limit is a normal part of importing years of data
 * out of an API - so it carries a time to try again rather than an exception.
 */
final class ImportBatchResult {

	/**
	 * @param int                 $processed   Source records read.
	 * @param int                 $imported    Rows written here.
	 * @param int                 $skipped     Records deliberately not imported.
	 * @param int                 $daysDone    Days completed by this batch.
	 * @param bool                $finished    Whether the whole import is done.
	 * @param int                 $retryAfter  Seconds to wait, when the source asked us to.
	 * @param string              $message     A sentence for the user, if there is one.
	 * @param string|null         $technical   Detail for the log, never for the screen.
	 * @param array<string,mixed> $cursor The importer's place, for the next batch.
	 */
	public function __construct(
		public readonly int $processed = 0,
		public readonly int $imported = 0,
		public readonly int $skipped = 0,
		public readonly int $daysDone = 0,
		public readonly bool $finished = false,
		public readonly int $retryAfter = 0,
		public readonly string $message = '',
		public readonly ?string $technical = null,
		public readonly array $cursor = []
	) {
	}

	/**
	 * The whole thing is done.
	 *
	 * @param array<string,mixed> $cursor Final cursor, kept for the record.
	 */
	public static function complete( array $cursor = [] ): self {
		return new self( finished: true, cursor: $cursor );
	}

	/**
	 * Not now - ask again in a moment.
	 *
	 * @param int                 $seconds   How long to wait.
	 * @param string              $message   What to tell the user meanwhile.
	 * @param array<string,mixed> $cursor    Where we got to.
	 * @param string|null         $technical Detail for the log.
	 */
	public static function waiting( int $seconds, string $message, array $cursor = [], ?string $technical = null ): self {
		return new self(
			retryAfter: max( 1, $seconds ),
			message: $message,
			technical: $technical,
			cursor: $cursor
		);
	}
}
