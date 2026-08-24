<?php
/**
 * Spool backlog reporting.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How much is waiting to be counted.
 *
 * Deliberately does not parse the spool: this is asked on a dashboard render
 * and on every auto-drain check, so it has to cost one `filesize()` or one
 * indexed count, not a pass over a file.
 */
final class SpoolStatus {

	private Settings $settings;

	/**
	 * Memoised verdict, so a run of admin questions costs one count.
	 */
	private ?bool $concerning = null;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Bytes waiting in the file spool.
	 */
	public function backlogBytes(): int {
		$path = Paths::spoolFile();

		if ( ! is_file( $path ) ) {
			return 0;
		}

		clearstatcache( true, $path );

		$size = filesize( $path );

		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Rows waiting in the database queue.
	 */
	public function queuedRows(): int {
		global $wpdb;

		$table = Tables::name( Tables::SPOOL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether anything is waiting, on whichever driver is in use.
	 *
	 * Asks whether there is a row rather than how many there are. This is the
	 * question the auto-drain throttle asks after every page and every beacon,
	 * and on the healthy case - an empty spool file - it always falls through
	 * to the table. `COUNT(*)` on InnoDB walks the whole index to answer it,
	 * so on a site whose queue has backed up the cheapest possible check was
	 * scanning a million rows a minute on the table capture is writing to.
	 * `describe()` still counts, because there the number is the point.
	 */
	public function hasBacklog(): bool {
		global $wpdb;

		if ( $this->backlogBytes() > 0 ) {
			return true;
		}

		$table = Tables::name( Tables::SPOOL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		return null !== $wpdb->get_var( "SELECT id FROM `$table` LIMIT 1" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A human description of the backlog.
	 */
	public function describe(): string {
		$bytes = $this->backlogBytes();
		$rows  = $this->queuedRows();

		if ( $rows > 0 ) {
			/* translators: %s: number of queued hits. */
			return sprintf( _n( '%s hit waiting', '%s hits waiting', $rows, 'honest-analytics' ), number_format_i18n( $rows ) );
		}

		if ( $bytes > 0 ) {
			/* translators: %s: formatted file size. */
			return sprintf( __( '%s waiting in the spool', 'honest-analytics' ), size_format( $bytes ) );
		}

		return __( 'Nothing waiting', 'honest-analytics' );
	}

	/**
	 * Whether the backlog is large enough to be worth warning about.
	 *
	 * Both drivers, because `backlogBytes()` stats the spool *file* and is
	 * therefore always zero on the database queue - so this could never return
	 * true however far that queue backed up. The `db` driver is the one chosen
	 * for managed hosts with read-only filesystems, and on those
	 * `DbQueueWriter` starts dropping hits at its ceiling and says so only to a
	 * log that is off unless WP_DEBUG is on. Health had no way to know.
	 */
	public function isConcerning(): bool {
		if ( null !== $this->concerning ) {
			return $this->concerning;
		}

		if ( $this->backlogBytes() > (int) ( $this->settings->spoolMaxBytes * 0.25 ) ) {
			$this->concerning = true;

			return true;
		}

		// Memoised, and only reached on the database driver, because this is a
		// COUNT(*) and on InnoDB that walks the index. `Health::problems()`
		// asks twice and is itself asked several times per admin render; the
		// answer cannot meaningfully change in between.
		if ( Settings::WRITE_DB !== $this->settings->writeDriver ) {
			$this->concerning = false;

			return false;
		}

		$max = DbQueueWriter::maxRowsFor( $this->settings );

		$this->concerning = $this->queuedRows() > (int) ( $max * 0.25 );

		return $this->concerning;
	}
}
