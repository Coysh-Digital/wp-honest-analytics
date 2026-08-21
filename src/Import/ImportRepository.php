<?php
/**
 * Reading and writing import jobs.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only thing that touches the three import tables.
 *
 * Jobs, per-day coverage and the log all live behind here, so that the runner
 * can concern itself with running and the REST controller with answering. The
 * serialisation of the configuration and the cursor is this class's business
 * too: everywhere else they are arrays and objects, and only here are they
 * JSON in a column.
 */
final class ImportRepository {

	/**
	 * Create a job and return it with its id filled in.
	 *
	 * @param int                 $siteId        Site.
	 * @param string              $source        One of the ImportSource constants.
	 * @param ImportConfiguration $configuration What was asked for.
	 * @param array<string,mixed> $cursor        The importer's starting place.
	 * @param int                 $daysTotal     Days in the requested range.
	 */
	public function create( int $siteId, string $source, ImportConfiguration $configuration, array $cursor, int $daysTotal ): ImportJob {
		global $wpdb;

		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The imports table has no core API and is deliberately uncached.
		$wpdb->insert(
			Tables::name( Tables::IMPORTS ),
			[
				'siteId'        => $siteId,
				'source'        => $source,
				'status'        => ImportJob::STATUS_PENDING,
				'dateFrom'      => $configuration->dateFrom,
				'dateTo'        => $configuration->dateTo,
				'configuration' => (string) wp_json_encode( $configuration->toArray() ),
				'cursorState'   => (string) wp_json_encode( $cursor ),
				'daysTotal'     => $daysTotal,
				'startedAt'     => $now,
				'updatedAt'     => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new ImportJob(
			id: (int) $wpdb->insert_id,
			siteId: $siteId,
			source: $source,
			status: ImportJob::STATUS_PENDING,
			configuration: $configuration,
			cursor: $cursor,
			daysTotal: $daysTotal,
			startedAt: $now,
			updatedAt: $now
		);
	}

	/**
	 * One job by id.
	 *
	 * @param int $id Row id.
	 */
	public function find( int $id ): ?ImportJob {
		global $wpdb;

		$table = Tables::name( Tables::IMPORTS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The imports table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every job for a site, newest first.
	 *
	 * @param int $siteId Site.
	 * @param int $limit  How many.
	 *
	 * @return ImportJob[]
	 */
	public function forSite( int $siteId, int $limit = 50 ): array {
		global $wpdb;

		$table = Tables::name( Tables::IMPORTS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The imports table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE siteId = %d ORDER BY id DESC LIMIT %d", $siteId, max( 1, $limit ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( [ self::class, 'hydrate' ], (array) $rows );
	}

	/**
	 * The most recent jobs for a site, newest first.
	 *
	 * @param int $siteId Site.
	 * @param int $limit  How many.
	 *
	 * @return ImportJob[]
	 */
	public function recent( int $siteId, int $limit = 50 ): array {
		return $this->forSite( $siteId, $limit );
	}

	/**
	 * Stop a job by id.
	 *
	 * The screen has an id and wants it stopped; what stopping means is the
	 * runner's decision, so this only saves the caller a lookup.
	 *
	 * @param int $id Row id.
	 */
	public function cancel( int $id ): void {
		$job = $this->find( $id );

		if ( null !== $job ) {
			( new ImportRunner( $this ) )->cancel( $job );
		}
	}

	/**
	 * The most recent job for one source, whatever its state.
	 *
	 * What the import screen shows against each source: an import in progress,
	 * one that finished, or one that failed and can be tried again.
	 *
	 * @param int    $siteId Site.
	 * @param string $source Source.
	 */
	public function latestFor( int $siteId, string $source ): ?ImportJob {
		global $wpdb;

		$table = Tables::name( Tables::IMPORTS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The imports table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE siteId = %d AND source = %s ORDER BY id DESC LIMIT 1", $siteId, $source ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The sources this site has already dealt with.
	 *
	 * Anything with a job that finished, or one still running, counts. A
	 * cancelled or failed job does not: that history is still waiting to be
	 * brought across, and the offer to bring it should still stand.
	 *
	 * @param int $siteId Site.
	 *
	 * @return string[] Source ids.
	 */
	public function handledSources( int $siteId ): array {
		global $wpdb;

		$table    = Tables::name( Tables::IMPORTS );
		$statuses = [
			ImportJob::STATUS_COMPLETE,
			ImportJob::STATUS_PENDING,
			ImportJob::STATUS_RUNNING,
			ImportJob::STATUS_WAITING,
		];

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The imports table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT source FROM `$table` WHERE siteId = %d AND status IN ( $placeholders )",
				array_merge( [ $siteId ], $statuses )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Jobs with work left to do, oldest first, ready to run now.
	 *
	 * The `retryAfter` filter is what makes a rate limit a pause rather than a
	 * spin: a job told to wait ten minutes is simply not returned until then.
	 *
	 * @param int      $limit How many.
	 * @param int|null $now   Timestamp, for tests.
	 *
	 * @return ImportJob[]
	 */
	public function due( int $limit = 5, ?int $now = null ): array {
		global $wpdb;

		$now   = $now ?? time();
		$table = Tables::name( Tables::IMPORTS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The imports table has no core API and is deliberately uncached; the identifier comes from Schema\Tables and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `$table` WHERE status IN ( %s, %s, %s ) AND retryAfter <= %d ORDER BY id ASC LIMIT %d",
				ImportJob::STATUS_PENDING,
				ImportJob::STATUS_RUNNING,
				ImportJob::STATUS_WAITING,
				$now,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( [ self::class, 'hydrate' ], (array) $rows );
	}

	/**
	 * Write a job's mutable state back.
	 *
	 * @param ImportJob $job The job.
	 */
	public function save( ImportJob $job ): void {
		global $wpdb;

		if ( $job->id <= 0 ) {
			return;
		}

		$job->updatedAt = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The imports table has no core API and is deliberately uncached.
		$wpdb->update(
			Tables::name( Tables::IMPORTS ),
			[
				'status'           => $job->status,
				'cursorState'      => (string) wp_json_encode( $job->cursor ),
				'recordsProcessed' => $job->recordsProcessed,
				'recordsImported'  => $job->recordsImported,
				'recordsSkipped'   => $job->recordsSkipped,
				'daysTotal'        => $job->daysTotal,
				'daysDone'         => $job->daysDone,
				'attempts'         => $job->attempts,
				'lastError'        => substr( $job->lastError, 0, 2000 ),
				'retryAfter'       => $job->retryAfter,
				'updatedAt'        => $job->updatedAt,
				'completedAt'      => $job->completedAt,
			],
			[ 'id' => $job->id ],
			[ '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d' ],
			[ '%d' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Turn a database row into a job.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private static function hydrate( array $row ): ImportJob {
		$configuration = json_decode( (string) ( $row['configuration'] ?? '' ), true );
		$cursor        = json_decode( (string) ( $row['cursorState'] ?? '' ), true );

		return new ImportJob(
			id: (int) $row['id'],
			siteId: (int) $row['siteId'],
			source: (string) $row['source'],
			status: (string) $row['status'],
			configuration: ImportConfiguration::fromArray( is_array( $configuration ) ? $configuration : [] ),
			cursor: is_array( $cursor ) ? $cursor : [],
			recordsProcessed: (int) $row['recordsProcessed'],
			recordsImported: (int) $row['recordsImported'],
			recordsSkipped: (int) $row['recordsSkipped'],
			daysTotal: (int) $row['daysTotal'],
			daysDone: (int) $row['daysDone'],
			attempts: (int) $row['attempts'],
			lastError: (string) ( $row['lastError'] ?? '' ),
			retryAfter: (int) $row['retryAfter'],
			startedAt: (int) $row['startedAt'],
			updatedAt: (int) $row['updatedAt'],
			completedAt: (int) $row['completedAt']
		);
	}
}
