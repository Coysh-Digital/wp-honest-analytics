<?php
/**
 * Lazy schema upgrades.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

use HonestAnalytics\Support\Lock;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings a site's schema up to date whenever something is about to use it.
 *
 * Activation is not a reliable moment on multisite, and a plugin updated by
 * FTP or by a deploy never runs one at all. So the check is cheap - an option
 * read against a constant - and it runs from cron and from the CLI bootstrap.
 *
 * **Not from a visitor request, and not from an admin page load.** dbDelta on a
 * current schema is close to free, but an upgrade that has real work to do can
 * be an `ALTER TABLE` against millions of rows, and nobody's page load should
 * wait for that. The drain stands down instead: `Drainer::run()` returns
 * immediately while `isCurrent()` is false, leaving the spool intact for the
 * run that follows the migration. Writing against a stale schema is what used
 * to happen - `Upsert` hit `Unknown column`, `Support\Db` threw, three attempts,
 * quarantined, for every batch, while the spool climbed to its ceiling and
 * `SpoolWriter` began dropping hits.
 */
final class Upgrader {

	/** MySQL named lock, so two workers cannot migrate the same site at once. */
	private const LOCK_NAME = 'schema';

	/** Long enough to queue behind a short migration, short enough not to hold a cron run. */
	private const LOCK_WAIT = 5;

	/**
	 * Whether an upgrade has already run this request.
	 */
	private static bool $ran = false;

	/**
	 * Upgrade if the stored version is behind.
	 */
	public static function maybeUpgrade(): void {
		if ( self::$ran ) {
			return;
		}

		self::$ran = true;

		if ( self::isCurrent() ) {
			return;
		}

		// Under a lock, because two workers arriving together both used to run
		// the migration. `widenBucketKeys()` dropped a unique key and added it
		// back, and a second worker inside that window could leave the table
		// with no unique key at all - permanently, because the version was
		// bumped whatever happened.
		$lock = new Lock( self::LOCK_NAME );

		if ( ! $lock->acquire( self::LOCK_WAIT ) ) {
			return;
		}

		try {
			// Re-read now the lock is held: whoever won the race has finished,
			// and repeating their work is at best wasted ALTERs.
			if ( self::isCurrent() ) {
				return;
			}

			Installer::installSite();
		} catch ( \Throwable $e ) {
			Log::error( 'Schema upgrade failed: ' . $e->getMessage() );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Whether the schema is installed and current.
	 *
	 * Impure, and the impurity is the point: this reads an option another
	 * worker may have written since the last call, which is exactly what
	 * `maybeUpgrade()` re-checks once it holds the lock.
	 *
	 * @phpstan-impure
	 */
	public static function isCurrent(): bool {
		return (int) get_option( Installer::VERSION_OPTION, 0 ) === Schema::VERSION;
	}

	/**
	 * Reset the guard. For tests.
	 */
	public static function reset(): void {
		self::$ran = false;
	}
}
