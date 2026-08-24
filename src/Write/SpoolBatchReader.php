<?php
/**
 * Claiming, reading and setting aside spool files.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\CaptureService;
use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Losses;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file half of the drain: which batch is ours, what is in it, and what
 * happens to it afterwards.
 *
 * `Drainer` was doing two jobs. This one is about files on disk - claim by
 * rename, read a chunk at a time, count a failure, quarantine after three,
 * remove a batch that is done, and remember where a partly-drained file was
 * read up to. The other is the apply and commit lifecycle: what a chunk means,
 * which transaction it belongs in, and whether it has already been counted.
 * Separating them leaves each small enough to hold in the head, and makes the
 * boundary between "bytes" and "figures" a real one rather than a convention.
 *
 * Every failure here is deliberately soft. A count that cannot be written, a
 * rename that does not happen, an offset that is lost - none of those is worth
 * an exception, because the batch is still on disk and the next run finds it.
 * The writes that must not fail quietly are on the other side of that line, in
 * the transaction, and they go through `Support\Db`.
 */
final class SpoolBatchReader {

	public const CLAIMED_SUFFIX   = '.processing';
	public const FAILED_SUFFIX    = '.failed';
	private const ATTEMPTS_SUFFIX = '.attempts';
	private const MAX_ATTEMPTS    = 3;

	/**
	 * @param int $chunkHits Hits materialised at once.
	 */
	public function __construct( private int $chunkHits ) {
	}

	/**
	 * Take ownership of whatever is waiting.
	 *
	 * @return string[]
	 */
	public function claimFiles(): array {
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
	 * The key a batch's resume offset is stored under.
	 *
	 * @param string $batchId Batch id.
	 */
	private static function progressKey( string $batchId ): string {
		return 'drain-offset:' . $batchId;
	}

	/**
	 * Seek to where the last run stopped, and say which chunk that was.
	 *
	 * Falls back to the beginning whenever the stored offset is missing or no
	 * longer plausible - a shorter file means something else has been written,
	 * and re-reading is the safe answer. The chunk markers make either path
	 * idempotent; the offset only decides how much work it takes to get there.
	 *
	 * @param resource $handle  Open file handle.
	 * @param string   $batchId Batch id.
	 *
	 * @return int The chunk index to carry on from.
	 */
	public function resume( $handle, string $batchId ): int {
		$stored = StoreFactory::keyValue()->get( self::progressKey( $batchId ) );

		if ( ! is_array( $stored ) || ! isset( $stored['offset'], $stored['chunk'] ) ) {
			return 0;
		}

		$offset = (int) $stored['offset'];
		$chunk  = (int) $stored['chunk'];

		$stats = fstat( $handle );
		$size  = is_array( $stats ) && isset( $stats['size'] ) ? (int) $stats['size'] : 0;

		if ( $offset <= 0 || $chunk <= 0 || $offset > $size ) {
			return 0;
		}

		if ( 0 !== fseek( $handle, $offset ) ) {
			return 0;
		}

		return $chunk;
	}

	/**
	 * Record where this file has been read up to.
	 *
	 * Written after the commit, never before: an offset ahead of the marker
	 * would skip a chunk that was never applied, which loses hits. Behind it
	 * only costs a re-read that the marker then skips.
	 *
	 * @param resource $handle  Open file handle.
	 * @param string   $batchId Batch id.
	 * @param int      $chunk   The next chunk index.
	 */
	public function rememberProgress( $handle, string $batchId, int $chunk ): void {
		$offset = ftell( $handle );

		if ( false === $offset ) {
			return;
		}

		StoreFactory::keyValue()->set(
			self::progressKey( $batchId ),
			[
				'offset' => $offset,
				'chunk'  => $chunk,
			],
			DAY_IN_SECONDS
		);
	}

	/**
	 * Read up to one chunk of usable hits.
	 *
	 * @param resource $handle Open file handle.
	 *
	 * @return array{0:Hit[],1:int}
	 */
	public function readChunk( $handle ): array {
		$hits      = [];
		$malformed = 0;

		while ( count( $hits ) < $this->chunkHits ) {
			// Bounded, because the writer's line limit binds the writer and not
			// this. A spool whose last line was cut short by a full disk, or
			// one an unrelated process appended to, would otherwise be read
			// into a single string as long as the file.
			$line = fgets( $handle, SpoolWriter::MAX_LINE_BYTES + 2 );

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
	 * Count a failure, and quarantine after enough of them.
	 *
	 * @param string      $file   File path.
	 * @param DrainResult $result Result, updated in place.
	 */
	public function recordFailure( string $file, DrainResult $result ): void {
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

		Losses::record( Losses::QUARANTINED );

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
		// carries on regardless - losing the count is not worth an exception,
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
	public function discard( string $file ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file . self::ATTEMPTS_SUFFIX );

		// The resume offset goes with the file it described. It would expire on
		// its own, but leaving one row per drained batch in the store for a day
		// is a needless pile on a busy site.
		StoreFactory::keyValue()->delete( self::progressKey( basename( $file, self::CLAIMED_SUFFIX ) ) );
	}

	/**
	 * Whether a hit carries an identity the pipeline can use.
	 *
	 * Crawlers carry a deliberate sentinel rather than a hash, so demanding hex
	 * of everything would drop every crawler record as malformed.
	 *
	 * @param Hit $hit The hit.
	 */
	public static function hasUsableIdentity( Hit $hit ): bool {
		if ( Hit::KIND_CRAWLER === $hit->kind ) {
			return CaptureService::CRAWLER_HASH === $hit->visitorHash;
		}

		return IdentityService::isValidHash( $hit->visitorHash );
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
	public function requeueFailed(): int {
		$count = 0;

		foreach ( $this->failedFiles() as $file ) {
			$target = substr( $file, 0, -strlen( self::FAILED_SUFFIX ) ) . self::CLAIMED_SUFFIX;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $file, $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $target . self::ATTEMPTS_SUFFIX );
				++$count;
			}
		}

		return $count;
	}
}
