<?php
/**
 * The Import data screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Import\Ga4\Client as Ga4Client;
use HonestAnalytics\Import\Ga4\Connection as Ga4Connection;
use HonestAnalytics\Import\Coverage;
use HonestAnalytics\Import\ImportConfiguration;
use HonestAnalytics\Import\ImporterInterface;
use HonestAnalytics\Import\ImportRunner;
use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Import\Scheduler;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bringing somebody's history across, one step at a time.
 *
 * This is the first screen a person sees after installing, and quite possibly
 * the moment they decide whether switching was a good idea. So it is a wizard
 * rather than a database utility: no table names, no cursors, no cron, no OAuth
 * vocabulary, and nothing that asks the reader to understand how any of it
 * works. One question per screen, in plain words, with the honest caveat about
 * measurement shown before the import rather than buried in documentation.
 *
 * The screen talks to the import runner through the narrow private helpers at
 * the bottom of this class. Each one is guarded, because the runner and the
 * three importers are separate moving parts: a build without them should show
 * an honest empty state rather than a white screen.
 */
final class ImportScreen extends Screen {

	/** The landing screen: which sources are here. */
	/**
	 * The source key used when the coverage check could not be run.
	 *
	 * Not a real source. It exists so that "we could not tell" reaches the
	 * screen as a row somebody has to look at rather than as an empty list,
	 * which the wizard would read as "nothing overlaps".
	 */
	private const OVERLAP_UNKNOWN = '__unknown__';

	private const STEP_SOURCES = 'sources';

	/** How measurement differs, before anything else. */
	private const STEP_CONNECT = 'connect';

	/** Choosing which Google Analytics property to read. */
	private const STEP_NOTES = 'notes';

	/** How much history to bring. */
	private const STEP_RANGE = 'range';

	/** Only when another source already covers some of it. */
	private const STEP_OVERLAP = 'overlap';

	/** What was found, before committing. */
	private const STEP_PREVIEW = 'preview';

	/** The bar. */
	private const STEP_PROGRESS = 'progress';

	/** What came across. */
	private const STEP_COMPLETE = 'complete';

	/** For anybody who wants the detail. */
	private const STEP_DETAILS = 'details';

	/**
	 * A message to show at the top of the screen, if something just happened.
	 */
	private string $notice = '';

	/**
	 * Whether that message is a problem rather than an outcome.
	 */
	private bool $noticeIsProblem = false;

	/**
	 * The job this request created or is watching.
	 */
	private ?ImportJob $job = null;

	/**
	 * Every job this site has run, read once.
	 *
	 * `lastJobFor()` walks this list and the sources partial calls it once per
	 * importer, so without the memo one render of the first step is six
	 * identical queries. Safe because every action that creates or changes a
	 * job redirects afterwards, so nothing populated during a render can go
	 * stale within it.
	 *
	 * @var ImportJob[]|null
	 */
	private ?array $jobs = null;

	/**
	 * The page slug.
	 */
	public function slug(): string {
		return 'honest-analytics-import';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Import data', 'honest-analytics' );
	}

	/**
	 * Importing writes analytics, so it needs the settings capability rather
	 * than the reading one.
	 */
	public function capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * Prose and choices, not tables.
	 */
	public function maxWidth(): int {
		return 900;
	}

	/**
	 * Nothing here is bounded by the global date range.
	 */
	public function hasRange(): bool {
		return false;
	}

	/**
	 * No charts.
	 */
	public function usesCharts(): bool {
		return false;
	}

	/**
	 * The heading follows the step, so the page says where you are.
	 */
	public function heading(): string {
		switch ( $this->step() ) {
			case self::STEP_DETAILS:
				return __( 'Import details', 'honest-analytics' );

			case self::STEP_PROGRESS:
				return __( 'Importing', 'honest-analytics' );

			case self::STEP_COMPLETE:
				return __( 'Import complete', 'honest-analytics' );
		}

		$importer = $this->importer();

		if ( null !== $importer && self::STEP_SOURCES !== $this->step() ) {
			/* translators: %s: the name of the analytics tool being imported from. */
			return sprintf( __( 'Import from %s', 'honest-analytics' ), $importer->name() );
		}

		return __( 'Import data', 'honest-analytics' );
	}

	/**
	 * Handle the one action this screen takes: starting an import.
	 */
	public function load(): void {
		parent::load();

		$this->readConnectionOutcome();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['honest_import_action'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_key( wp_unslash( (string) $_POST['honest_import_action'] ) )
			: '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'honest-analytics-import' );

		if ( 'start' === $action ) {
			$this->startImport();

			return;
		}

		if ( 'cancel' === $action ) {
			$this->cancelImport();
		}
	}

	/**
	 * Turn the `ga4` query value into something a person can read.
	 *
	 * The Google connection leaves its result in the URL rather than in the
	 * session, because the round trip goes through somebody else's server and
	 * comes back as a plain redirect. Reading it here is what stops every
	 * outcome, good and bad, from being a silent return to this screen.
	 */
	private function readConnectionOutcome(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A word to display, not an instruction to act on; it is matched against a fixed list before anything is shown.
		$result = isset( $_GET['ga4'] ) ? sanitize_key( wp_unslash( (string) $_GET['ga4'] ) ) : '';

		if ( '' === $result || ! class_exists( Ga4Connection::class ) ) {
			return;
		}

		$outcome = Ga4Connection::outcome( $result );

		if ( null === $outcome ) {
			return;
		}

		$this->notice          = $outcome['message'];
		$this->noticeIsProblem = $outcome['problem'];
	}

	/**
	 * Load the polling script on the progress step only.
	 */
	public function enqueue(): void {
		parent::enqueue();

		if ( self::STEP_PROGRESS !== $this->step() ) {
			return;
		}

		$job = $this->watchedJob();

		if ( null === $job || 0 === $job->id ) {
			return;
		}

		wp_enqueue_script(
			'honest-analytics-import',
			HONEST_ANALYTICS_URL . 'assets/admin/js/import.js',
			[],
			HONEST_ANALYTICS_VERSION,
			true
		);

		wp_localize_script(
			'honest-analytics-import',
			'honestAnalyticsImport',
			[
				'progress' => rest_url( 'honest-analytics/v1/import/' . $job->id ),
				'tick'     => rest_url( 'honest-analytics/v1/import/' . $job->id . '/tick' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'interval' => 3000,
				'locale'   => str_replace( '_', '-', get_locale() ),
				'done'     => $this->url(
					[
						'step' => self::STEP_COMPLETE,
						'job'  => (string) $job->id,
					]
				),
				'strings'  => [
					/* translators: %s: a number of records, already formatted. */
					'processed' => __( '%s records processed', 'honest-analytics' ),
					'starting'  => __( 'Getting started…', 'honest-analytics' ),
					'waiting'   => __( 'Paused for a moment, then carrying on automatically.', 'honest-analytics' ),
					'stalled'   => __( 'This is taking longer than expected. Your progress is saved, and the import will carry on in the background - you can safely leave this page.', 'honest-analytics' ),
				],
			]
		);
	}

	/**
	 * Render whichever step we are on.
	 */
	public function render(): void {
		View::render(
			'admin/import',
			[
				'screen'          => $this,
				'params'          => $this->params(),
				'step'            => $this->step(),
				'importer'        => $this->importer(),
				'importers'       => $this->importers(),
				'job'             => $this->watchedJob(),
				'notice'          => $this->notice,
				'noticeIsProblem' => $this->noticeIsProblem,
				'available'       => $this->runnerAvailable(),
				'connection'      => $this->connection(),
			]
		);
	}

	/**
	 * Everything the Google Analytics steps need, and no token.
	 *
	 * Null when this build has no GA4 layer in it, which the templates treat as
	 * "not available" rather than as an error.
	 *
	 * @return array<string,mixed>|null
	 */
	public function connection(): ?array {
		if ( ! class_exists( Ga4Connection::class ) ) {
			return null;
		}

		try {
			return Ga4Connection::status();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The accounts and properties this connection can see.
	 *
	 * Asked for only on the step that lists them, because it is a request to
	 * Google and the rest of the wizard has no use for it. Never throws: a
	 * screen should not have to catch an API failure in order to draw itself.
	 *
	 * @return array{accounts:array<int,array<string,mixed>>,error:string,detailed:bool}
	 */
	public function accounts(): array {
		if ( ! class_exists( Ga4Connection::class ) ) {
			return [
				'accounts' => [],
				'error'    => '',
				'detailed' => false,
			];
		}

		$found = Ga4Connection::accounts();

		return [
			'accounts' => is_array( $found['accounts'] ?? null ) ? $found['accounts'] : [],
			'error'    => (string) ( $found['error'] ?? '' ),
			'detailed' => (bool) ( $found['detailed'] ?? false ),
		];
	}

	/* ------------------------------------------------------------- the state */

	/**
	 * Which step the request asked for.
	 */
	public function step(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( (string) $_GET['step'] ) ) : '';

		$known = [
			self::STEP_SOURCES,
			self::STEP_CONNECT,
			self::STEP_NOTES,
			self::STEP_RANGE,
			self::STEP_OVERLAP,
			self::STEP_PREVIEW,
			self::STEP_PROGRESS,
			self::STEP_COMPLETE,
			self::STEP_DETAILS,
		];

		if ( ! in_array( $step, $known, true ) ) {
			return self::STEP_SOURCES;
		}

		// Every step past the first needs to know which source it is about.
		if ( self::STEP_SOURCES !== $step && ! in_array( $step, [ self::STEP_PROGRESS, self::STEP_COMPLETE, self::STEP_DETAILS ], true ) && null === $this->importer() ) {
			return self::STEP_SOURCES;
		}

		return $step;
	}

	/**
	 * The source named in the request, if it is one we have.
	 */
	public function importer(): ?ImporterInterface {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '';

		if ( '' === $id ) {
			return null;
		}

		foreach ( $this->importers() as $importer ) {
			if ( $importer->id() === $id ) {
				return $importer;
			}
		}

		return null;
	}

	/**
	 * A URL for this screen with the given parameters.
	 *
	 * @param array<string,string|null> $args Query arguments.
	 */
	public function url( array $args = [] ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( (string) $_GET['source'] ) ) : '';

		if ( '' !== $source && ! array_key_exists( 'source', $args ) ) {
			$args['source'] = $source;
		}

		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				unset( $args[ $key ] );
			}
		}

		return add_query_arg( $args, menu_page_url( $this->slug(), false ) );
	}

	/**
	 * Back to the list of sources.
	 */
	public function homeUrl(): string {
		return $this->url(
			[
				'source' => null,
				'step'   => null,
				'job'    => null,
			]
		);
	}

	/**
	 * A step in the wizard, carrying the source it is about.
	 *
	 * @param string      $step   One of the step names.
	 * @param string|null $source Source id, or null to keep the current one.
	 */
	public function stepUrl( string $step, ?string $source = null ): string {
		$args = [ 'step' => $step ];

		if ( null !== $source ) {
			$args['source'] = $source;
			$args['job']    = null;
		}

		return $this->url( $args );
	}

	/**
	 * A step about a particular job rather than a particular source.
	 *
	 * @param string $step  One of the step names.
	 * @param int    $jobId The job.
	 */
	public function jobUrl( string $step, int $jobId ): string {
		return $this->url(
			[
				'step'   => $step,
				'job'    => (string) $jobId,
				'source' => null,
			]
		);
	}

	/**
	 * The dates a source could give us, as a range the wizard can offer.
	 *
	 * Asks inspect() rather than detect(): detect() only reads whatever is
	 * already cached, and reading a range that has never been discovered
	 * yet - true the first time anybody reaches this step for a freshly
	 * chosen property - fell back to today on each side independently. That
	 * read as "we found one day of analytics" for a property with years of
	 * it. inspect() is allowed to cost a request, and callers of this method
	 * are already on a step dedicated to the one chosen source.
	 *
	 * @param ImporterInterface $importer The source.
	 *
	 * @return array{from:string,to:string}
	 */
	public function availableRange( ImporterInterface $importer ): array {
		$summary = $importer->inspect();

		$today = Timezone::at( time() )->format( 'Y-m-d' );

		return [
			'from' => '' !== $summary->dateFrom ? $summary->dateFrom : $today,
			'to'   => '' !== $summary->dateTo ? $summary->dateTo : $today,
		];
	}

	/**
	 * The range the wizard is currently carrying, from the query string.
	 *
	 * The wizard keeps its choices in the URL rather than in a session: the
	 * back button then works, a step can be bookmarked, and nothing is stored
	 * anywhere until the person presses the button that starts the import.
	 *
	 * @param ImporterInterface $importer The source.
	 *
	 * @return array{choice:string,from:string,to:string,overlap:string}
	 */
	public function requestedRange( ImporterInterface $importer ): array {
		$available = $this->availableRange( $importer );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which step of a wizard to draw, before anything is written.
		$choice  = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( (string) $_GET['range'] ) ) : 'all';
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		$to      = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';
		$overlap = isset( $_GET['overlap'] ) ? sanitize_key( wp_unslash( (string) $_GET['overlap'] ) ) : ImportConfiguration::OVERLAP_SKIP;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$dates = self::datesFor( $available, $choice, $from, $to );

		return [
			'choice'  => $dates['choice'],
			'from'    => $dates['from'],
			'to'      => $dates['to'],
			'overlap' => ImportConfiguration::OVERLAP_REPLACE === $overlap
				? ImportConfiguration::OVERLAP_REPLACE
				: ImportConfiguration::OVERLAP_SKIP,
		];
	}

	/**
	 * The dates a range choice means, given what the source has.
	 *
	 * One implementation, because there used to be two: the screen worked out
	 * what "the last 12 months" meant one way when drawing the form and another
	 * when reading it back, and the two had already drifted apart - one going
	 * through `wp_date()` and the other through `Timezone::at()`. A rule about
	 * which days somebody is about to import four years of history for is not a
	 * rule to hold in two places.
	 *
	 * Never wider than the source actually has, whichever branch is taken.
	 *
	 * @param array{from:string,to:string} $available What the source holds.
	 * @param string                       $choice    all, year or custom.
	 * @param string                       $from      Custom start, if any.
	 * @param string                       $to        Custom end, if any.
	 *
	 * @return array{choice:string,from:string,to:string}
	 */
	private static function datesFor( array $available, string $choice, string $from, string $to ): array {
		if ( 'custom' === $choice && DateRange::isValidParam( $from . ':' . $to ) ) {
			$dateFrom = $from;
			$dateTo   = $to;
		} elseif ( 'year' === $choice ) {
			$dateFrom = Timezone::at(
				strtotime( '-12 months', (int) Timezone::middayOn( $available['to'] ) ) ?: time()
			)->format( 'Y-m-d' );

			$dateTo = $available['to'];
		} else {
			$choice   = 'all';
			$dateFrom = $available['from'];
			$dateTo   = $available['to'];
		}

		return [
			'choice' => $choice,
			'from'   => (string) max( $dateFrom, $available['from'] ),
			'to'     => (string) min( $dateTo, $available['to'] ),
		];
	}

	/**
	 * Which days in a range are already covered by another import.
	 *
	 * Read straight from the coverage table, which is the one thing that knows.
	 * A day appears there once it has been imported, whichever source did it,
	 * so this is also what stops two overlapping sources being added together.
	 *
	 * @param string $source The source about to import.
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 *
	 * @return array<string,array{from:string,to:string,days:int}> Keyed by the covering source.
	 */
	public function overlapping( string $source, string $from, string $to ): array {
		if ( ! $this->coverageTableExists() ) {
			return [];
		}

		try {
			// One implementation, in Coverage. This used to be a second copy of
			// the same GROUP BY, and the copy cast a failed `get_results()` to
			// an empty array - which the wizard reads as "nothing overlaps" and
			// walks the user straight past the question. Guessing "no clash" is
			// the unsafe direction: it is the one that ends in two sources being
			// added together.
			return ( new Coverage() )->clashSummary( $this->siteId(), $source, $from, $to );
		} catch ( \Throwable $e ) {
			Log::warning( 'Could not check which dates are already imported: ' . $e->getMessage() );

			// Asked rather than assumed. A row the user can see, on the step
			// that makes them choose skip or replace deliberately, is the right
			// answer to "we do not know" - silence is not.
			return [
				self::OVERLAP_UNKNOWN => [
					'from' => $from,
					'to'   => $to,
					'days' => 0,
				],
			];
		}
	}

	/**
	 * The last import run for a source, if there was one.
	 *
	 * @param string $source Source id.
	 */
	public function lastJobFor( string $source ): ?ImportJob {
		foreach ( $this->jobs() as $job ) {
			if ( $job->source === $source ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * Every job this site has run, newest first.
	 *
	 * @return ImportJob[]
	 */
	public function jobs(): array {
		if ( null !== $this->jobs ) {
			return $this->jobs;
		}

		$repository = $this->repository();

		if ( null === $repository || ! method_exists( $repository, 'recent' ) ) {
			$this->jobs = [];

			return $this->jobs;
		}

		$jobs = $repository->recent( $this->siteId() );

		$this->jobs = is_array( $jobs ) ? $jobs : [];

		return $this->jobs;
	}

	/**
	 * Whether the machinery that actually runs an import is present.
	 *
	 * A build without it can still show what was found, which is worth more
	 * than an error, but the buttons have to be honest about it.
	 */
	public function runnerAvailable(): bool {
		return class_exists( ImportRunner::class ) && null !== $this->repository();
	}

	/* --------------------------------------------------------------- actions */

	/**
	 * Create the job and hand it to the runner.
	 */
	private function startImport(): void {
		$importer = $this->importer();

		if ( null === $importer ) {
			return;
		}

		if ( ! $this->runnerAvailable() ) {
			$this->notice          = __( 'Importing is not available on this installation.', 'honest-analytics' );
			$this->noticeIsProblem = true;

			return;
		}

		$configuration = $this->configurationFromRequest( $importer );

		// The runner rather than the repository. A row created straight in the
		// table has no importer state behind it and would sit at 0% for ever;
		// begin() asks the importer for its opening cursor and works out how
		// many days there are to do, which is what the progress bar counts.
		if ( ! class_exists( ImportRunner::class ) ) {
			$this->notice          = __( 'The import could not be started. Nothing has been changed.', 'honest-analytics' );
			$this->noticeIsProblem = true;

			return;
		}

		try {
			$job = ( new ImportRunner() )->begin( $this->siteId(), $importer->id(), $configuration );
		} catch ( \Throwable $e ) {
			$this->notice          = __( 'The import could not be started. Nothing has been changed, and nothing in your other analytics tool has been touched.', 'honest-analytics' );
			$this->noticeIsProblem = true;

			return;
		}

		$this->job = $job;

		// Ask for a batch now rather than waiting for the five-minute drain.
		// With JavaScript the progress screen ticks the import along itself, so
		// this was never noticed; without it - a locked-down admin, a blocked
		// REST route, a browser extension - the first thing that happened after
		// pressing Start was five minutes of nothing.
		Scheduler::nudge();

		// Straight to the progress step, so the first thing the person sees
		// after pressing the button is the thing moving.
		wp_safe_redirect(
			$this->url(
				[
					'step' => self::STEP_PROGRESS,
					'job'  => (string) $job->id,
				]
			)
		);

		exit;
	}

	/**
	 * Stop a running import. What was already brought across stays.
	 */
	private function cancelImport(): void {
		$job = $this->watchedJob();

		if ( null === $job ) {
			return;
		}

		$repository = $this->repository();

		if ( null === $repository || ! method_exists( $repository, 'cancel' ) ) {
			return;
		}

		try {
			$repository->cancel( $job->id );
		} catch ( \Throwable $e ) {
			$this->notice          = __( 'The import could not be stopped just now.', 'honest-analytics' );
			$this->noticeIsProblem = true;

			return;
		}

		// Redirected, like starting one is, and to the step that describes a
		// finished import. Rendering in place after the POST drew a progress
		// bar still ticking underneath the words "the import has been stopped"
		// - the job was read into memory before it was cancelled, so the page
		// showed the state it had a moment ago - and a refresh posted the
		// cancellation again.
		wp_safe_redirect(
			$this->url(
				[
					'step' => self::STEP_COMPLETE,
					'job'  => (string) $job->id,
				]
			)
		);

		exit;
	}

	/**
	 * Turn the posted choices into a configuration.
	 *
	 * @param ImporterInterface $importer The source.
	 */
	private function configurationFromRequest( ImporterInterface $importer ): ImportConfiguration {
		$available = $this->availableRange( $importer );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in load().
		$choice  = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( (string) $_POST['range'] ) ) : 'all';
		$from    = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '';
		$to      = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '';
		$overlap = isset( $_POST['overlap'] ) ? sanitize_key( wp_unslash( (string) $_POST['overlap'] ) ) : ImportConfiguration::OVERLAP_SKIP;
		// map_deep() rather than array_map(): it walks nested arrays instead of
		// handing one to sanitize_text_field(), which takes a string and would
		// be a TypeError under strict_types. A wizard step posting
		// `options[a][b]` took this screen down rather than being ignored.
		// ImportController::options() has always had that guard; this copy did
		// not.
		$posted  = isset( $_POST['options'] ) && is_array( $_POST['options'] )
			? (array) map_deep( wp_unslash( $_POST['options'] ), 'sanitize_text_field' )
			: [];
		$options = [];

		foreach ( $posted as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			// Not sanitize_key(): that lower-cases, and an option posted as
			// `propertyName` is read back by exactly that name.
			$name = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );

			if ( '' !== $name ) {
				$options[ substr( $name, 0, 64 ) ] = (string) $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$dates    = self::datesFor( $available, $choice, $from, $to );
		$dateFrom = $dates['from'];
		$dateTo   = $dates['to'];

		if ( ImportSource::GA4 === $importer->id() && class_exists( Ga4Connection::class ) ) {
			// Pinned into the job rather than read at batch time. Choosing a
			// different property while a four-year import is still running
			// would otherwise mix two websites into one history.
			$options = array_merge( $options, Ga4Connection::importOptions() );
		}

		return new ImportConfiguration(
			$dateFrom,
			$dateTo,
			ImportConfiguration::OVERLAP_REPLACE === $overlap
				? ImportConfiguration::OVERLAP_REPLACE
				: ImportConfiguration::OVERLAP_SKIP,
			$options
		);
	}

	/* --------------------------------------------------- talking to the runner */

	/**
	 * The importers this build has.
	 *
	 * Ordered so that anything found on this site comes first: a person who
	 * has WP Statistics installed should not have to read past two things they
	 * have never used to find it.
	 *
	 * @return ImporterInterface[]
	 */
	public function importers(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$registry = '\\HonestAnalytics\\Import\\ImporterRegistry';

		if ( ! class_exists( $registry ) ) {
			$cache = [];

			return $cache;
		}

		$importers = $registry::all();
		$found     = [];
		$rest      = [];

		foreach ( is_array( $importers ) ? $importers : [] as $importer ) {
			if ( ! $importer instanceof ImporterInterface ) {
				continue;
			}

			if ( $importer->detect()->isAvailable() ) {
				$found[] = $importer;

				continue;
			}

			$rest[] = $importer;
		}

		$cache = array_merge( $found, $rest );

		return $cache;
	}

	/**
	 * The job the request is about.
	 */
	public function watchedJob(): ?ImportJob {
		if ( null !== $this->job ) {
			return $this->job;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['job'] ) ? absint( wp_unslash( $_GET['job'] ) ) : 0;

		if ( 0 === $id ) {
			return null;
		}

		$repository = $this->repository();

		if ( null === $repository || ! method_exists( $repository, 'find' ) ) {
			return null;
		}

		$job = $repository->find( $id );

		if ( ! $job instanceof ImportJob || $job->siteId !== $this->siteId() ) {
			return null;
		}

		$this->job = $job;

		return $this->job;
	}

	/**
	 * The repository, if this build has one.
	 *
	 * Assumed shape, and the one thing to reconcile if the runner lands with a
	 * different API: a no-argument constructor, plus find(), create(),
	 * cancel() and recent().
	 */
	private function repository(): ?object {
		static $repository = false;

		if ( false !== $repository ) {
			return $repository;
		}

		$class = '\\HonestAnalytics\\Import\\ImportRepository';

		if ( ! class_exists( $class ) ) {
			$repository = null;

			return null;
		}

		$repository = new $class();

		return $repository;
	}

	/**
	 * Whether the coverage table is installed.
	 */
	private function coverageTableExists(): bool {
		return class_exists( '\\HonestAnalytics\\Schema\\Schema' )
			&& \HonestAnalytics\Schema\Schema::tableExists( Tables::IMPORT_COVERAGE );
	}

	/**
	 * The name a source is known by, even when its importer is absent.
	 *
	 * @param string $source Source id.
	 */
	public function sourceLabel( string $source ): string {
		if ( self::OVERLAP_UNKNOWN === $source ) {
			return __( 'Could not be checked', 'honest-analytics' );
		}

		return ImportSource::label( $source );
	}
}
