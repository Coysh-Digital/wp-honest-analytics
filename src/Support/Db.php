<?php
/**
 * Statements that must not fail quietly.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages thrown here are the driver's error text for the log; every caller catches, rolls back and logs, and none prints the message.

/**
 * `$wpdb` reports a failed statement by returning false and carrying on.
 *
 * Inside a transaction that is the worst possible answer: the drain commits,
 * writes its batch marker, deletes the spool file, and the hits it could not
 * write are gone with nobody told. A deadlock is the ordinary case - InnoDB
 * rolls the whole transaction back on its own, after which every later
 * statement commits by itself - and a deadlock between a drain and a
 * compaction is not exotic on a busy site.
 *
 * Everything written under a transaction therefore goes through here, where a
 * false return becomes an exception, the caller's catch issues the rollback,
 * and the batch is retried rather than discarded.
 */
final class Db {

	/**
	 * Run a statement, or throw.
	 *
	 * @param string $sql A fully prepared statement.
	 *
	 * @return int Rows affected.
	 *
	 * @throws RuntimeException When the statement fails.
	 */
	public static function query( string $sql ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; the caller has already prepared the statement.
		$result = $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			throw new RuntimeException( self::describe( 'Statement failed' ) );
		}

		return (int) $result;
	}

	/**
	 * Read one column, or throw.
	 *
	 * `get_col()` answers a failed query with an empty array, which is also
	 * how it answers "nothing matched". Anywhere the difference decides
	 * whether something is deleted, the two must not look alike.
	 *
	 * @param string $sql A fully prepared statement.
	 *
	 * @return string[] The column, as strings.
	 *
	 * @throws RuntimeException When the query fails.
	 */
	public static function col( string $sql ): array {
		global $wpdb;

		$wpdb->last_error = '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; the caller has already prepared the statement.
		$values = $wpdb->get_col( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new RuntimeException( self::describe( 'Column read failed' ) );
		}

		return array_map( 'strval', (array) $values );
	}

	/**
	 * Read rows, or throw.
	 *
	 * `get_results()` answers a failed query with null, which every caller that
	 * casts to an array then cannot tell from "nothing matched". Where the
	 * difference decides whether rows are about to be deleted and rewritten,
	 * the two must not look alike.
	 *
	 * @param string $sql A fully prepared statement.
	 *
	 * @return array<int,array<string,mixed>> The rows, as associative arrays.
	 *
	 * @throws RuntimeException When the query fails.
	 */
	public static function rows( string $sql ): array {
		global $wpdb;

		$wpdb->last_error = '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; the caller has already prepared the statement.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			throw new RuntimeException( self::describe( 'Row read failed' ) );
		}

		$out = [];

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Insert a row, or throw.
	 *
	 * @param string                        $table  Prefixed table name.
	 * @param array<string,mixed>           $data   Column => value.
	 * @param array<int,string>|string|null $format Value formats, as `$wpdb->insert()` takes them.
	 *
	 * @throws RuntimeException When the insert fails.
	 */
	public static function insert( string $table, array $data, array|string|null $format = null ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached.
		$result = $wpdb->insert( $table, $data, $format );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			throw new RuntimeException( self::describe( 'Insert into ' . $table . ' failed' ) );
		}
	}

	/**
	 * The error text, when the driver has one.
	 *
	 * The message carries the server's reason and nothing from the statement
	 * itself: a rollup row holds nothing personal, but the log should not have
	 * to rely on that.
	 *
	 * @param string $what What was being attempted.
	 */
	private static function describe( string $what ): string {
		global $wpdb;

		$error = isset( $wpdb->last_error ) ? trim( (string) $wpdb->last_error ) : '';

		return '' === $error ? $what . '.' : $what . ': ' . $error;
	}
}
