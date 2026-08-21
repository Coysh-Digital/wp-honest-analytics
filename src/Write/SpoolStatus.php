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
	 */
	public function hasBacklog(): bool {
		return $this->backlogBytes() > 0 || $this->queuedRows() > 0;
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
	 */
	public function isConcerning(): bool {
		return $this->backlogBytes() > (int) ( $this->settings->spoolMaxBytes * 0.25 );
	}
}
