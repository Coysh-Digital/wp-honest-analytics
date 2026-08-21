<?php
/**
 * What happened during an import.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A per-import log, for the details screen and for support.
 *
 * Separate from the plugin's ordinary debug log because the questions are
 * different. `Support\Log` answers "is something broken on this site"; this
 * answers "why did that four-year import take three days and end up eleven
 * thousand rows short". Batch by batch, with the cursor, so somebody reading it
 * afterwards can see exactly where it slowed down or gave up.
 *
 * **Nothing sensitive goes in here.** No tokens, no API secrets, no addresses,
 * no anything a visitor typed. An import log is a support artefact and support
 * artefacts get emailed around; it holds counts, durations and the names of
 * things, and that is all it will ever hold.
 */
final class ImportLog {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * How many lines are kept per import.
	 *
	 * A four-year GA4 import is a few hundred batches. Keeping the last five
	 * hundred lines means a completed import keeps all of them and a pathological
	 * one keeps the part that matters - the end.
	 */
	private const KEEP = 500;

	/** Trim every so often rather than on every line: one DELETE per batch is plenty. */
	private const TRIM_EVERY = 25;

	/**
	 * Keys that never reach the log, whatever a caller passes.
	 *
	 * Belt and braces. Nothing should be handing these over in the first place,
	 * but the cost of the list is nothing and the cost of a token in a support
	 * ticket is somebody's Google account.
	 */
	private const FORBIDDEN = [
		'token',
		'access_token',
		'refresh_token',
		'accesstoken',
		'refreshtoken',
		'secret',
		'client_secret',
		'clientsecret',
		'password',
		'key',
		'api_key',
		'apikey',
		'authorization',
		'ip',
		'email',
	];

	/**
	 * Write a line.
	 *
	 * @param int                 $importId The job.
	 * @param string              $level    One of the constants above.
	 * @param string              $message  What happened, in a sentence.
	 * @param array<string,mixed> $context  Counts, durations, cursors. Never secrets.
	 */
	public static function write( int $importId, string $level, string $message, array $context = [] ): void {
		global $wpdb;

		if ( $importId <= 0 ) {
			return;
		}

		$table = Tables::name( Tables::IMPORT_LOG );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The import log has no core API and is deliberately uncached.
		$wpdb->insert(
			$table,
			[
				'importId' => $importId,
				'at'       => time(),
				'level'    => substr( $level, 0, 10 ),
				'message'  => substr( $message, 0, 2000 ),
				'context'  => [] === $context ? null : (string) wp_json_encode( self::scrub( $context ) ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( self::ERROR === $level ) {
			Log::warning( 'Import ' . $importId . ': ' . $message );
		}

		if ( 0 === wp_rand( 0, self::TRIM_EVERY - 1 ) ) {
			self::trim( $importId );
		}
	}

	/**
	 * The lines for one import, newest first.
	 *
	 * @param int $importId The job.
	 * @param int $limit    How many.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function read( int $importId, int $limit = 100 ): array {
		global $wpdb;

		$table = Tables::name( Tables::IMPORT_LOG );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The import log has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, at, level, message, context FROM `$table` WHERE importId = %d ORDER BY id DESC LIMIT %d",
				$importId,
				max( 1, min( self::KEEP, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): array {
				$context = null !== $row['context'] ? json_decode( (string) $row['context'], true ) : [];

				return [
					'id'      => (int) $row['id'],
					'at'      => (int) $row['at'],
					'level'   => (string) $row['level'],
					'message' => (string) $row['message'],
					'context' => is_array( $context ) ? $context : [],
				];
			},
			(array) $rows
		);
	}

	/**
	 * Drop everything but the most recent lines for one import.
	 *
	 * @param int $importId The job.
	 */
	public static function trim( int $importId ): void {
		global $wpdb;

		$table = Tables::name( Tables::IMPORT_LOG );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The import log has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$cutoff = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `$table` WHERE importId = %d ORDER BY id DESC LIMIT 1 OFFSET %d",
				$importId,
				self::KEEP
			)
		);

		if ( null !== $cutoff ) {
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM `$table` WHERE importId = %d AND id <= %d", $importId, (int) $cutoff )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove anything that looks like a secret before it is written.
	 *
	 * @param array<string,mixed> $context Caller's context.
	 *
	 * @return array<string,mixed>
	 */
	private static function scrub( array $context ): array {
		$out = [];

		foreach ( $context as $key => $value ) {
			$name = strtolower( (string) $key );

			foreach ( self::FORBIDDEN as $forbidden ) {
				if ( str_contains( $name, $forbidden ) ) {
					continue 2;
				}
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = self::scrub( $value );

				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}
}
