<?php
/**
 * Database-queue writer.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Losses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per hit, for hosts where a shared file will not do.
 *
 * Two cases need this. A read-only filesystem, which several managed hosts
 * enforce outside the uploads directory and some enforce inside it. And
 * multi-server setups, where each web node would keep its own spool file - or,
 * worse, share one over NFS, where `flock` is advisory at best and torn lines
 * are a real outcome.
 *
 * It costs one INSERT per hit, which is more than appending a line and far less
 * than aggregating on the request. The drain claims rows in bulk, so the read
 * side is unchanged.
 */
final class DbQueueWriter implements WriterInterface {

	/** Set while the queue is over its bound, so most writes cost one cache read. */
	private const FULL_KEY = 'queue-full';

	/** Holds the depth check down to one COUNT(*) a minute, however much traffic arrives. */
	private const CHECK_KEY = 'queue-check';

	/** Seconds between depth checks, and how long a "full" verdict stands. */
	private const CHECK_INTERVAL = 60;

	/** Never bound the queue below this, however small the configured spool. */
	private const MIN_ROWS = 1000;

	private KeyValueStoreInterface $store;

	/** The depth verdict for this request, decided at most once. */
	private ?bool $fullThisRequest = null;

	/**
	 * @param Settings                    $settings Settings.
	 * @param KeyValueStoreInterface|null $store    Store, for the depth check.
	 */
	public function __construct(
		private Settings $settings,
		?KeyValueStoreInterface $store = null
	) {
		$this->store = $store ?? StoreFactory::keyValue();
	}

	/**
	 * Queue a hit.
	 *
	 * @param Hit $hit The hit.
	 */
	public function write( Hit $hit ): void {
		global $wpdb;

		try {
			$line = $hit->encode();

			if ( strlen( $line ) > SpoolWriter::MAX_LINE_BYTES ) {
				Log::warning( 'Dropped an oversized queued hit.' );
				Losses::record( Losses::OVERSIZED );

				return;
			}

			// Back-pressure. A queue that nothing is draining will fill the
			// database until something else on the site breaks, and an
			// analytics table is not the thing worth breaking a site over.
			// Dropping is visible on the Settings screen; a full disk is not.
			if ( $this->isFull() ) {
				Log::warning( 'The write queue is at its limit - dropping hits until it drains.' );
				Losses::record( Losses::FULL );

				return;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->insert(
				Tables::name( Tables::SPOOL ),
				[
					'siteId'    => $hit->siteId,
					'batchId'   => null,
					'line'      => $line,
					'createdAt' => $hit->timestamp,
				],
				[ '%d', '%s', '%s', '%d' ]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			Log::warning( 'Queued write failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether the queue has grown past its bound.
	 *
	 * Counting rows on every hit would cost more than the insert. Instead the
	 * count runs once a minute and its verdict is cached, so the common path
	 * is a single cache read - and, within one request, no read at all.
	 *
	 * The per-request memo matters more here than it looks. Without a
	 * persistent object cache every store call is a query, and this is the
	 * driver chosen precisely because round trips are expensive: three extra
	 * queries beside each insert would triple the cost of the thing being
	 * defended. The verdict only changes once a minute, so it cannot go stale
	 * inside a single request.
	 */
	private function isFull(): bool {
		if ( null !== $this->fullThisRequest ) {
			return $this->fullThisRequest;
		}

		if ( true === $this->store->get( self::FULL_KEY ) ) {
			$this->fullThisRequest = true;

			return true;
		}

		// add() succeeds only for the first caller in the interval. Everybody
		// else takes the cached verdict and moves on.
		if ( ! $this->store->add( self::CHECK_KEY, 1, self::CHECK_INTERVAL ) ) {
			$this->fullThisRequest = false;

			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$table = Tables::name( Tables::SPOOL );
		$depth = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $depth >= $this->maxRows() ) {
			$this->store->set( self::FULL_KEY, true, self::CHECK_INTERVAL );

			$this->fullThisRequest = true;

			return true;
		}

		$this->store->delete( self::FULL_KEY );

		$this->fullThisRequest = false;

		return false;
	}

	/**
	 * How many rows the queue may hold.
	 *
	 * Expressed as a byte budget in the settings, because that is the number an
	 * administrator can reason about, and converted here using the same line
	 * limit the file spool enforces.
	 */
	public function maxRows(): int {
		return self::maxRowsFor( $this->settings );
	}

	/**
	 * The same ceiling, without needing the writer itself.
	 *
	 * `SpoolStatus` has to know it to say whether the queue is filling up, and
	 * it has the settings but not the container. Duplicating the arithmetic
	 * there would be two places to change when the line limit moves.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function maxRowsFor( Settings $settings ): int {
		return max(
			self::MIN_ROWS,
			intdiv( $settings->spoolMaxBytes, SpoolWriter::MAX_LINE_BYTES )
		);
	}

	/**
	 * The driver name.
	 */
	public function name(): string {
		return Settings::WRITE_DB;
	}
}
