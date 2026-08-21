<?php
/**
 * Looking at what another plugin's tables actually contain.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only introspection of a source plugin's tables.
 *
 * Both plugins this imports from have reshaped their schema more than once, and
 * the site being imported may be on any version of either - including a version
 * whose tables were left behind when the plugin was deleted. So nothing here
 * assumes: it asks what tables exist and what columns they have, and the
 * importers map whatever they find.
 *
 * The alternative is a version check, and a version check is wrong twice over.
 * The plugin may not be installed to have a version, and two installs claiming
 * the same version can still differ if one skipped a migration.
 *
 * Everything is cached for the request, because detection runs on every load of
 * the import screen and `SHOW COLUMNS` is not free.
 */
final class SourceSchema {

	/**
	 * Tables that exist, keyed by unprefixed name.
	 *
	 * @var array<string,bool>
	 */
	private static array $tables = [];

	/**
	 * Columns per table, keyed by unprefixed name.
	 *
	 * @var array<string,string[]>
	 */
	private static array $columns = [];

	/**
	 * Whether a table exists on this site.
	 *
	 * @param string $table Unprefixed table name, such as `statistics_pages`.
	 */
	public static function hasTable( string $table ): bool {
		if ( isset( self::$tables[ $table ] ) ) {
			return self::$tables[ $table ];
		}

		global $wpdb;

		$name = $wpdb->prefix . $table;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's tables have no core API, and the answer is memoised for the request.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::$tables[ $table ] = (string) $found === $name;

		return self::$tables[ $table ];
	}

	/**
	 * The columns of a table, lower-cased for comparison.
	 *
	 * Returns an empty list for a table that does not exist, so a caller can
	 * ask about columns without asking about the table first.
	 *
	 * @param string $table Unprefixed table name.
	 *
	 * @return string[]
	 */
	public static function columns( string $table ): array {
		if ( isset( self::$columns[ $table ] ) ) {
			return self::$columns[ $table ];
		}

		if ( ! self::hasTable( $table ) ) {
			self::$columns[ $table ] = [];

			return [];
		}

		global $wpdb;

		$name = $wpdb->prefix . $table;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's tables have no core API; the name is built from $wpdb->prefix and a literal, and the answer is memoised.
		$rows = $wpdb->get_col( "SHOW COLUMNS FROM `$name`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::$columns[ $table ] = array_map( 'strtolower', array_map( 'strval', (array) $rows ) );

		return self::$columns[ $table ];
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param string $table  Unprefixed table name.
	 * @param string $column Column name.
	 */
	public static function hasColumn( string $table, string $column ): bool {
		return in_array( strtolower( $column ), self::columns( $table ), true );
	}

	/**
	 * The first of several candidate columns that the table actually has.
	 *
	 * The workhorse of both importers. A source that renamed `last_counter` to
	 * `date` between versions is handled by asking for either and using
	 * whichever is there, rather than by branching on a version number nobody
	 * can trust.
	 *
	 * @param string   $table      Unprefixed table name.
	 * @param string[] $candidates Column names, in order of preference.
	 */
	public static function firstColumn( string $table, array $candidates ): ?string {
		$present = self::columns( $table );

		foreach ( $candidates as $candidate ) {
			if ( in_array( strtolower( $candidate ), $present, true ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * A rough row count, without counting.
	 *
	 * `SHOW TABLE STATUS` reports InnoDB's own estimate, which is wrong by some
	 * percentage and free. `SELECT COUNT(*)` is exact and, on a table with
	 * fifteen million analytics rows, takes long enough that somebody would
	 * reasonably assume the screen had hung. The preview says "approximately"
	 * for exactly this reason.
	 *
	 * @param string $table Unprefixed table name.
	 */
	public static function approximateRows( string $table ): int {
		if ( ! self::hasTable( $table ) ) {
			return 0;
		}

		global $wpdb;

		$name = $wpdb->prefix . $table;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's tables have no core API and this is a one-row estimate.
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $name ) ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? (int) ( $row['Rows'] ?? 0 ) : 0;
	}

	/**
	 * The prefixed, quoted name of a source table.
	 *
	 * @param string $table Unprefixed table name.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Forget everything memoised. For tests.
	 */
	public static function flush(): void {
		self::$tables  = [];
		self::$columns = [];
	}
}
