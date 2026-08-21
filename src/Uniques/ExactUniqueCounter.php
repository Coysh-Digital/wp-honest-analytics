<?php
/**
 * Exact unique counting.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Uniques;

use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The opt-in alternative: one row per visitor per scope.
 *
 * This is the only counter that can answer "exactly how many", and it is not
 * the default, because the price is a table whose size grows with traffic
 * rather than with cardinality - the one growth curve the rest of this plugin
 * exists to avoid.
 *
 * What it stores is still a daily anonymous hash and nothing else: no address,
 * no identifier that outlives the salt, and no way to ask which pages a row
 * visited, because the scope key is the only thing a row is filed under. The
 * hashes stop matching anything the moment the salt rotates, and the garbage
 * collector removes them two rotations later.
 */
final class ExactUniqueCounter implements UniqueCounterInterface {

	/**
	 * Rows written per statement.
	 *
	 * A busy hour arrives as one drain, so the insert has to be batched; a
	 * placeholder list in the thousands is what `max_allowed_packet` refuses.
	 */
	private const CHUNK = 500;

	/**
	 * File visitor hashes under a scope.
	 *
	 * @param UniqueScope $scope   Scope.
	 * @param string[]    $hashes  Visitor hashes.
	 * @param string|null $current Unused: this counter stores nothing on the row.
	 */
	public function record( UniqueScope $scope, array $hashes, ?string $current ): ?string {
		global $wpdb;

		unset( $current );

		$hashes = array_values(
			array_unique(
				array_filter( array_map( 'strval', $hashes ), [ IdentityService::class, 'isValidHash' ] )
			)
		);

		if ( [] === $hashes ) {
			return null;
		}

		$table = Tables::name( Tables::UNIQUE_MEMBERS );
		$key   = $scope->key();

		foreach ( array_chunk( $hashes, self::CHUNK ) as $chunk ) {
			$rows = [];
			$args = [];

			foreach ( $chunk as $hash ) {
				$rows[] = '(%s, %d, %s, %s)';

				$args[] = $key;
				$args[] = $scope->siteId;
				$args[] = $scope->date;
				$args[] = $hash;
			}

			// INSERT IGNORE against the (scopeKey, visitorHash) unique key is
			// what makes a repeat visit free: the second view of the day is a
			// duplicate the database drops, not a counter this code has to read
			// back and compare.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `$table` (scopeKey, siteId, date, visitorHash) VALUES " . implode( ', ', $rows ),
					$args
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		return null;
	}

	/**
	 * Count the distinct visitors across a set of scopes.
	 *
	 * COUNT(DISTINCT) rather than a sum of per-scope counts, for the same
	 * reason the sketch driver merges rather than adds: somebody who read
	 * something on Monday and again on Friday is one visitor, and adding the
	 * daily figures would make them two.
	 *
	 * @param UniqueScope[]             $scopes   Scopes to union.
	 * @param array<string,string|null> $sketches Unused: nothing is stored on the row.
	 */
	public function estimate( array $scopes, array $sketches ): int {
		global $wpdb;

		unset( $sketches );

		$keys = $this->keys( $scopes );

		if ( [] === $keys ) {
			return 0;
		}

		$table = Tables::name( Tables::UNIQUE_MEMBERS );
		$total = 0;

		foreach ( array_chunk( $keys, self::CHUNK ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$total += (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(DISTINCT visitorHash) FROM `$table` WHERE scopeKey IN ($in)", $chunk )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		return $total;
	}

	/**
	 * The members live in their own table.
	 */
	public function storesOnRow(): bool {
		return false;
	}

	/**
	 * Re-file a day's hourly members under its daily scope.
	 *
	 * @param UniqueScope   $daily  Destination.
	 * @param UniqueScope[] $hourly Sources.
	 */
	public function compact( UniqueScope $daily, array $hourly ): void {
		global $wpdb;

		$keys = $this->keys( $hourly );

		if ( [] === $keys ) {
			return;
		}

		$table = Tables::name( Tables::UNIQUE_MEMBERS );

		foreach ( array_chunk( $keys, self::CHUNK ) as $chunk ) {
			$in   = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$args = array_merge( [ $daily->key() ], $chunk );

			// Copied rather than moved: a visitor seen in three different hours
			// is three rows here and one under the daily key, and an UPDATE
			// would have to decide which of the three survives.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `$table` (scopeKey, siteId, date, visitorHash)
					SELECT %s, siteId, date, visitorHash FROM `$table` WHERE scopeKey IN ($in)",
					$args
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Delete scopes that have been folded away.
	 *
	 * @param UniqueScope[] $scopes Scopes.
	 */
	public function discardCompacted( array $scopes ): void {
		global $wpdb;

		$keys = $this->keys( $scopes );

		if ( [] === $keys ) {
			return;
		}

		$table = Tables::name( Tables::UNIQUE_MEMBERS );

		foreach ( array_chunk( $keys, self::CHUNK ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM `$table` WHERE scopeKey IN ($in)", $chunk )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
	}

	/**
	 * How accurate the figures are, in words a report can print.
	 */
	public function accuracy(): string {
		return __( 'exact', 'honest-analytics' );
	}

	/**
	 * The implementation name.
	 */
	public function name(): string {
		return Settings::UNIQUES_EXACT;
	}

	/**
	 * The distinct scope keys in a list of scopes.
	 *
	 * @param UniqueScope[] $scopes Scopes.
	 *
	 * @return string[]
	 */
	private function keys( array $scopes ): array {
		$keys = [];

		foreach ( $scopes as $scope ) {
			$keys[ $scope->key() ] = true;
		}

		return array_map( 'strval', array_keys( $keys ) );
	}
}
