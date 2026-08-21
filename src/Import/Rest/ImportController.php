<?php
/**
 * What the import screen talks to.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Rest;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Import\ImportConfiguration;
use HonestAnalytics\Import\ImporterRegistry;
use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Import\ImportLog;
use HonestAnalytics\Import\ImportRepository;
use HonestAnalytics\Import\ImportRunner;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Import\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Start an import, keep it moving, report on it, stop it.
 *
 * Behind a capability and a nonce, like every other administrative route here.
 * The `tick` route is the interesting one: the progress screen calls it every
 * few seconds, and each call runs one batch. That makes an open browser tab the
 * most reliable clock the plugin has, which matters because WP-Cron on a cached
 * site is not one.
 */
final class ImportController {

	/** How many log lines the details screen gets. */
	private const LOG_LINES = 60;

	private ImportRunner $runner;
	private ImportRepository $repository;

	/**
	 * @param ImportRunner|null $runner The batch loop.
	 */
	public function __construct( ?ImportRunner $runner = null ) {
		$this->runner     = $runner ?? new ImportRunner();
		$this->repository = $this->runner->repository();
	}

	/**
	 * Build one.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Who may ask.
	 */
	public static function permission(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Begin an import.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		$source = (string) $request->get_param( 'source' );

		if ( ! ImportSource::isValid( $source ) || ImportSource::NATIVE === $source ) {
			return $this->error( __( 'That is not something this plugin can import from.', 'honest-analytics' ), 400 );
		}

		$importer = ImporterRegistry::get( $source );

		if ( null === $importer ) {
			return $this->error( __( 'That import is not available on this site.', 'honest-analytics' ), 400 );
		}

		$detection = ImporterRegistry::detect( $source );

		if ( ! $detection->isAvailable() ) {
			return $this->error(
				'' !== $detection->detail
					? $detection->detail
					: __( 'There is nothing to import from this source.', 'honest-analytics' ),
				400
			);
		}

		$from = $this->date( (string) $request->get_param( 'from' ), (string) ( $detection->dateFrom ?? '' ) );
		$to   = $this->date( (string) $request->get_param( 'to' ), (string) ( $detection->dateTo ?? '' ) );

		if ( '' === $from || '' === $to || $to < $from ) {
			return $this->error( __( 'That date range does not look right. Please choose a start date before the end date.', 'honest-analytics' ), 400 );
		}

		$overlap = (string) $request->get_param( 'overlap' );

		$configuration = new ImportConfiguration(
			$from,
			$to,
			ImportConfiguration::OVERLAP_REPLACE === $overlap
				? ImportConfiguration::OVERLAP_REPLACE
				: ImportConfiguration::OVERLAP_SKIP,
			$this->options( $request )
		);

		try {
			$job = $this->runner->begin( get_current_blog_id(), $source, $configuration );
		} catch ( \Throwable $e ) {
			return $this->error( __( 'That import could not be started. Nothing has been changed.', 'honest-analytics' ), 400 );
		}

		Scheduler::nudge();

		return new \WP_REST_Response( $this->describe( $job ) );
	}

	/**
	 * Run a batch, and report where the job got to.
	 *
	 * Called by the progress screen on a timer. Returns the job whether or not
	 * this particular call did any work, so the screen always has something to
	 * draw.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function tick( \WP_REST_Request $request ): \WP_REST_Response {
		$job = $this->job( $request );

		if ( null === $job ) {
			return $this->error( __( 'That import could not be found.', 'honest-analytics' ), 404 );
		}

		if ( $job->isActive() && $job->retryAfter <= time() ) {
			$job = $this->runner->runBatch( $job );
		}

		if ( $job->isActive() ) {
			// Keep the background clock wound too, so closing the tab does not
			// stop the import.
			Scheduler::nudge( max( 1, $job->retryAfter - time() ) );
		}

		return new \WP_REST_Response( $this->describe( $job ) );
	}

	/**
	 * Where a job got to, without doing any work.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function progress( \WP_REST_Request $request ): \WP_REST_Response {
		$job = $this->job( $request );

		if ( null === $job ) {
			return $this->error( __( 'That import could not be found.', 'honest-analytics' ), 404 );
		}

		return new \WP_REST_Response( $this->describe( $job, (bool) $request->get_param( 'log' ) ) );
	}

	/**
	 * Stop a job.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function cancel( \WP_REST_Request $request ): \WP_REST_Response {
		$job = $this->job( $request );

		if ( null === $job ) {
			return $this->error( __( 'That import could not be found.', 'honest-analytics' ), 404 );
		}

		return new \WP_REST_Response( $this->describe( $this->runner->cancel( $job ) ) );
	}

	/**
	 * Turn a job into something the screen can draw.
	 *
	 * Every string here is written for a person. The technical detail lives in
	 * the import log, which the details screen shows separately for anybody who
	 * wants it.
	 *
	 * @param ImportJob $job     The job.
	 * @param bool      $withLog Whether to include recent log lines.
	 *
	 * @return array<string,mixed>
	 */
	private function describe( ImportJob $job, bool $withLog = false ): array {
		$out = [
			'id'               => $job->id,
			'source'           => $job->source,
			'sourceName'       => ImportSource::label( $job->source ),
			'status'           => $job->status,
			'active'           => $job->isActive(),
			'complete'         => $job->isComplete(),
			'percent'          => $job->percent(),
			'from'             => $job->configuration->dateFrom,
			'to'               => $job->configuration->dateTo,
			'daysTotal'        => $job->daysTotal,
			'daysDone'         => $job->daysDone,
			'imported'         => $job->recordsImported,
			'processed'        => $job->recordsProcessed,
			'skipped'          => $job->recordsSkipped,
			// The same figures again under the names the progress screen reads.
			// A payload is allowed to be generous where a database column is
			// not, and it is cheaper than either side renaming.
			'recordsProcessed' => $job->recordsProcessed,
			'recordsImported'  => $job->recordsImported,
			'finished'         => ! $job->isActive(),
			'retryAfter'       => max( 0, $job->retryAfter - time() ),
			'message'          => $job->lastError,
			'startedAt'        => $job->startedAt,
			'completedAt'      => $job->completedAt,
			'waitingUntil'     => $job->retryAfter > time() ? $job->retryAfter : 0,
		];

		if ( $withLog ) {
			$out['log'] = ImportLog::read( $job->id, self::LOG_LINES );
		}

		return $out;
	}

	/**
	 * The job named by the request, if it belongs to this site.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function job( \WP_REST_Request $request ): ?ImportJob {
		$job = $this->repository->find( (int) $request->get_param( 'id' ) );

		// A job id from another site in a network is not this site's to touch.
		return null !== $job && get_current_blog_id() === $job->siteId ? $job : null;
	}

	/**
	 * A date parameter, or a fallback.
	 *
	 * @param string $value    Raw parameter.
	 * @param string $fallback Used when the parameter is absent.
	 */
	private function date( string $value, string $fallback ): string {
		$value = trim( $value );

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fallback ) ? $fallback : '';
	}

	/**
	 * Importer-specific options from the request.
	 *
	 * Scalars only, keys sanitised: whatever an importer wants to remember it
	 * can remember, but not as arbitrary nested structures posted by a browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string,mixed>
	 */
	private function options( \WP_REST_Request $request ): array {
		$options = $request->get_param( 'options' );

		if ( ! is_array( $options ) ) {
			return [];
		}

		$out = [];

		foreach ( $options as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			// Not sanitize_key(): that lower-cases, and an option posted as
			// `propertyName` is read back by exactly that name. The case is
			// part of the key, so only the characters are restricted.
			$name = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );

			if ( '' !== $name ) {
				$out[ substr( $name, 0, 64 ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}

		return $out;
	}

	/**
	 * A refusal, in a sentence.
	 *
	 * @param string $message What went wrong.
	 * @param int    $status  HTTP status.
	 */
	private function error( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'message' => $message ], $status );
	}
}
