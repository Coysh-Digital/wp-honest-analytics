<?php
/**
 * The rotating visitor salt.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Identity;

use DateTimeImmutable;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Lock;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The secret that makes a visitor countable today and unknowable tomorrow.
 *
 * This class is the whole legal argument. Aggregate statistics are anonymous
 * rather than pseudonymous because the key that could link a hash back to a
 * person is destroyed on a schedule and is not archived anywhere: the row is
 * overwritten in place, there is no history table, and no backup of the old
 * value is kept. Lengthen the interval and that claim weakens; the Privacy
 * screen says so, and so does the settings help text.
 */
final class SaltService {

	private const CACHE_KEY  = 'salt';
	private const SALT_BYTES = 32;
	private const LOCK_NAME  = 'salt';
	private const LOCK_WAIT  = 3;

	private Settings $settings;
	private KeyValueStoreInterface $store;

	/**
	 * Memoised salt for this request.
	 */
	private ?string $memo = null;

	/**
	 * @param Settings                    $settings Settings.
	 * @param KeyValueStoreInterface|null $store    Store.
	 */
	public function __construct( Settings $settings, ?KeyValueStoreInterface $store = null ) {
		$this->settings = $settings;
		$this->store    = $store ?? StoreFactory::keyValue();
	}

	/**
	 * The salt in force right now, rotating it first if it is due.
	 */
	public function currentSalt(): string {
		if ( null !== $this->memo ) {
			return $this->memo;
		}

		$row = $this->load();

		if ( null === $row ) {
			$this->memo = $this->rotate();

			return $this->memo;
		}

		if ( $row['nextRotation'] <= time() ) {
			$this->memo = $this->rotateOnce();

			return $this->memo;
		}

		$this->memo = $row['salt'];

		return $this->memo;
	}

	/**
	 * When the salt is next due to be destroyed.
	 */
	public function nextRotation(): int {
		$row = $this->load();

		return null !== $row ? $row['nextRotation'] : time();
	}

	/**
	 * When the current salt came into force.
	 */
	public function rotatedAt(): int {
		$row = $this->load();

		return null !== $row ? $row['rotatedAt'] : time();
	}

	/**
	 * Rotate under a lock, re-reading once it is held.
	 *
	 * Without the lock, a burst of traffic crossing the boundary produces one
	 * salt per worker and splits that moment's visitors across several
	 * identities. With it, whoever loses the race re-reads and finds the salt
	 * the winner just wrote. If the lock cannot be taken at all the rotation
	 * happens anyway: a salt that is overdue is worse than one that is
	 * duplicated.
	 */
	public function rotateOnce(): string {
		$lock = new Lock( self::LOCK_NAME );

		if ( ! $lock->acquire( self::LOCK_WAIT ) ) {
			return $this->rotate();
		}

		try {
			$row = $this->load( true );

			if ( null !== $row && $row['nextRotation'] > time() ) {
				return $row['salt'];
			}

			return $this->rotate();
		} finally {
			$lock->release();
		}
	}

	/**
	 * Replace the salt and destroy the previous one.
	 */
	public function rotate(): string {
		global $wpdb;

		$salt  = bin2hex( random_bytes( self::SALT_BYTES ) );
		$now   = time();
		$next  = $this->nextRotationAfter( $now );
		$table = Tables::name( Tables::SALTS );

		$data = [
			'id'           => 1,
			'salt'         => $salt,
			'rotatedAt'    => gmdate( 'Y-m-d H:i:s', $now ),
			'nextRotation' => gmdate( 'Y-m-d H:i:s', $next ),
		];

		// Overwrite in place. The old salt is gone, not archived - that is the
		// point of the exercise, and a history table would quietly undo it.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$table` (id, salt, rotatedAt, nextRotation) VALUES (1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE salt = VALUES(salt), rotatedAt = VALUES(rotatedAt), nextRotation = VALUES(nextRotation)",
				$data['salt'],
				$data['rotatedAt'],
				$data['nextRotation']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->memo = $salt;

		$this->store->set(
			self::CACHE_KEY,
			[
				'salt'         => $salt,
				'rotatedAt'    => $now,
				'nextRotation' => $next,
			],
			max( 60, $next - $now )
		);

		return $salt;
	}

	/**
	 * When a salt minted now should next be replaced.
	 *
	 * Whole-day intervals are aligned to a configured hour, because a visitor
	 * active across the boundary becomes two sessions and two visitors - so the
	 * rotation is aimed at the site's quietest hour rather than at whenever the
	 * plugin happened to be installed.
	 *
	 * @param int $now Timestamp.
	 */
	public function nextRotationAfter( int $now ): int {
		$interval = max( 3600, $this->settings->saltRotationInterval );
		$target   = $now + $interval;

		if ( 0 !== $interval % 86400 ) {
			return $target;
		}

		$aligned = ( new DateTimeImmutable( '@' . $target ) )
			->setTimezone( Timezone::site() )
			->setTime( max( 0, min( 23, $this->settings->saltRotationHour ) ), 0 )
			->getTimestamp();

		return $aligned <= $now ? $aligned + 86400 : $aligned;
	}

	/**
	 * Read the salt row, from the store or the database.
	 *
	 * @param bool $refresh Skip the store.
	 *
	 * @return array{salt:string,rotatedAt:int,nextRotation:int}|null
	 */
	private function load( bool $refresh = false ): ?array {
		if ( ! $refresh ) {
			$cached = $this->store->get( self::CACHE_KEY );

			if ( is_array( $cached ) && isset( $cached['salt'], $cached['nextRotation'] ) ) {
				return [
					'salt'         => (string) $cached['salt'],
					'rotatedAt'    => (int) ( $cached['rotatedAt'] ?? 0 ),
					'nextRotation' => (int) $cached['nextRotation'],
				];
			}
		}

		global $wpdb;

		$table = Tables::name( Tables::SALTS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$row = $wpdb->get_row( "SELECT salt, rotatedAt, nextRotation FROM `$table` WHERE id = 1", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || empty( $row['salt'] ) ) {
			return null;
		}

		$parsed = [
			'salt'         => (string) $row['salt'],
			'rotatedAt'    => (int) strtotime( (string) $row['rotatedAt'] . ' UTC' ),
			'nextRotation' => (int) strtotime( (string) $row['nextRotation'] . ' UTC' ),
		];

		if ( $parsed['nextRotation'] <= 0 ) {
			Log::warning( 'Salt row has an unreadable rotation time; rotating.' );

			return null;
		}

		$this->store->set( self::CACHE_KEY, $parsed, max( 60, $parsed['nextRotation'] - time() ) );

		return $parsed;
	}

	/**
	 * Forget the memoised salt. For tests and for the CLI.
	 */
	public function flush(): void {
		$this->memo = null;
		$this->store->delete( self::CACHE_KEY );
	}
}
