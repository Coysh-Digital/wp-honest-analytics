<?php
/**
 * Append-only file spool.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Losses;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One line appended per hit, folded into totals a few minutes later.
 *
 * A thousand pageviews become a handful of database writes instead of a
 * thousand. The write itself is a single `fwrite` of a bounded line under an
 * exclusive lock, which is well inside what a filesystem writes atomically, so
 * concurrent appenders cannot interleave halfway through a line.
 */
final class SpoolWriter implements WriterInterface {

	/** Longer than this and something is wrong; a torn line is worse than a lost one. */
	public const MAX_LINE_BYTES = 4096;

	private Settings $settings;
	private ?string $path;

	/**
	 * @param Settings    $settings Settings.
	 * @param string|null $path     Spool file path, for tests.
	 */
	public function __construct( Settings $settings, ?string $path = null ) {
		$this->settings = $settings;
		$this->path     = $path;
	}

	/**
	 * Append a hit.
	 *
	 * @param Hit $hit The hit.
	 */
	public function write( Hit $hit ): void {
		try {
			$line = $hit->encode() . "\n";

			if ( strlen( $line ) > self::MAX_LINE_BYTES ) {
				Log::warning( 'Dropped an oversized spool line.' );
				Losses::record( Losses::OVERSIZED );

				return;
			}

			if ( $this->isOverCapacity() ) {
				Log::warning( 'The spool is at its configured ceiling; dropping this hit. Check that the drain is running.' );

				return;
			}

			$path = $this->path();

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = @fopen( $path, 'ab' );

			if ( false === $handle ) {
				Log::warning( 'Could not open the spool file for writing.' );

				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			if ( flock( $handle, LOCK_EX ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $handle, $line );
				fflush( $handle );
				flock( $handle, LOCK_UN );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		} catch ( \Throwable $e ) {
			// Analytics must never break the page it is measuring.
			Log::warning( 'Spool write failed: ' . $e->getMessage() );
		}
	}

	/**
	 * The driver name.
	 */
	public function name(): string {
		return Settings::WRITE_SPOOL;
	}

	/**
	 * The spool file path.
	 */
	public function path(): string {
		if ( null !== $this->path ) {
			return $this->path;
		}

		// Asked twice per hit, and resolving it means the uploads directory,
		// a filter and a stat or two each time. Once is enough: the directory
		// does not move between the capacity check and the write.
		Paths::spoolDir( true );

		$this->path = Paths::spoolFile();

		return $this->path;
	}

	/**
	 * Whether the spool has grown past its ceiling.
	 *
	 * The incoming hit is dropped rather than the oldest one: the file is
	 * append-only and rewriting it under load to make room would cost more than
	 * the data is worth. A spool at its ceiling means the drain is not running,
	 * which the settings screen says in as many words.
	 */
	public function isOverCapacity(): bool {
		$path = $this->path();

		if ( ! is_file( $path ) ) {
			return false;
		}

		clearstatcache( true, $path );

		return (int) filesize( $path ) >= max( 1048576, $this->settings->spoolMaxBytes );
	}
}
