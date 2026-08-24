<?php
/**
 * Importing from Independent Analytics.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Sources;

use DateTimeImmutable;
use DateTimeZone;
use HonestAnalytics\Import\DayBucket;
use HonestAnalytics\Import\DetectionResult;
use HonestAnalytics\Import\ImportBatchResult;
use HonestAnalytics\Import\ImportConfiguration;
use HonestAnalytics\Import\ImporterInterface;
use HonestAnalytics\Import\ImportJob;
use HonestAnalytics\Import\ImportLog;
use HonestAnalytics\Import\ImportRunner;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Import\ImportSummary;
use HonestAnalytics\Import\MetricMapping;
use HonestAnalytics\Plugin;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Independent Analytics' tables. Never writes to them.
 *
 * Two shapes are supported, because the plugin has had two: a newer trio of
 * `iawp_views`, `iawp_sessions` and `iawp_resources`, and an older single
 * visits table. Which one is present is worked out by looking, not by asking a
 * version number that may not exist.
 *
 * **The timezone matters here in a way it does not for WP Statistics.**
 * Independent Analytics stores a timestamp rather than a date, so a day has to
 * be decided rather than read. WordPress plugins conventionally store local
 * time, and that is the assumption made here - the timestamp's own calendar day
 * is the day it is filed under. It is also the assumption that cannot silently
 * shift a year of history sideways, because it does not convert anything.
 *
 * A site whose Independent Analytics recorded UTC can say so with the
 * `honest_analytics_import_iawp_timezone` filter. Either way the choice is
 * written into the import log, so a total that looks a day out has somewhere to
 * be traced to.
 */
final class IndependentAnalyticsImporter implements ImporterInterface {

	private const T_VIEWS     = 'iawp_views';
	private const T_SESSIONS  = 'iawp_sessions';
	private const T_RESOURCES = 'iawp_resources';
	private const T_LEGACY    = 'independent_analytics_visits';

	/** Days per batch, before the clock is consulted. */
	private const DAYS_PER_BATCH = 30;

	/**
	 * The column each thing lives in on this site.
	 *
	 * @var array<string,string|null>|null
	 */
	private ?array $map = null;

	/**
	 * Resource id to normalised path, for the length of one request.
	 *
	 * @var array<int,string>
	 */
	private array $resourcePaths = [];

	public function id(): string {
		return ImportSource::INDEPENDENT_ANALYTICS;
	}

	public function name(): string {
		return __( 'Independent Analytics', 'honest-analytics' );
	}

	/**
	 * Is there any Independent Analytics data on this site?
	 */
	public function detect(): DetectionResult {
		if ( ! $this->present() ) {
			return DetectionResult::none( __( 'Not detected on this website.', 'honest-analytics' ) );
		}

		$map = $this->map();

		if ( null === $map['viewsDate'] ) {
			return new DetectionResult(
				DetectionResult::UNSUPPORTED,
				__( 'Independent Analytics is here, but its data is stored in a layout this version does not recognise.', 'honest-analytics' )
			);
		}

		[ $from, $to ] = $this->range();

		if ( null === $from || null === $to ) {
			return new DetectionResult(
				DetectionResult::EMPTY_SOURCE,
				__( 'Independent Analytics is installed, but there is no history in it yet.', 'honest-analytics' )
			);
		}

		return new DetectionResult(
			DetectionResult::AVAILABLE,
			__( 'Historical data is ready to import.', 'honest-analytics' ),
			$from,
			$to,
			SourceSchema::approximateRows( $this->viewsTable() ),
			true,
			$this->notes()
		);
	}

	/**
	 * What an import would bring across.
	 */
	public function inspect(): ImportSummary {
		[ $from, $to ] = $this->range();

		$map        = $this->map();
		$dimensions = [ __( 'Page views', 'honest-analytics' ) ];
		$totals     = [
			[
				'label' => __( 'page views', 'honest-analytics' ),
				'count' => SourceSchema::approximateRows( $this->viewsTable() ),
			],
		];

		if ( null !== $map['sessionsDate'] ) {
			$dimensions[] = __( 'Visitors and sessions', 'honest-analytics' );

			$totals[] = [
				'label' => __( 'sessions', 'honest-analytics' ),
				'count' => SourceSchema::approximateRows( self::T_SESSIONS ),
			];
		}

		if ( null !== $map['sessionReferrer'] ) {
			$dimensions[] = __( 'Referrers', 'honest-analytics' );
		}

		if ( null !== $map['sessionCountry'] ) {
			$dimensions[] = __( 'Countries', 'honest-analytics' );
		}

		if ( null !== $map['sessionDevice'] || null !== $map['sessionBrowser'] ) {
			$dimensions[] = __( 'Devices and browsers', 'honest-analytics' );
		}

		$notes = $this->notes();

		if ( null !== $from && (string) $from < self::retentionCutoff() ) {
			$notes[] = __( 'Some of this history is older than your current retention setting. It will be kept regardless - imported history is exempt from the retention window.', 'honest-analytics' );
		}

		return new ImportSummary(
			(string) ( $from ?? '' ),
			(string) ( $to ?? '' ),
			$totals,
			true,
			$dimensions,
			[ __( 'Addresses, visitor signatures and anything else that identifies a person', 'honest-analytics' ) ],
			$this->mappings(),
			$notes
		);
	}

	/**
	 * The oldest date this site's own retention setting would otherwise keep.
	 */
	private static function retentionCutoff(): string {
		return ( new DateTimeImmutable( '@' . time() ) )
			->setTimezone( Timezone::site() )
			->modify( '-' . Plugin::instance()->settings()->rollupRetentionMonths . ' months' )
			->format( 'Y-m-d' );
	}

	/**
	 * How each of Independent Analytics' figures becomes one of ours.
	 *
	 * @return MetricMapping[]
	 */
	public function mappings(): array {
		return [
			new MetricMapping(
				__( 'Views', 'honest-analytics' ),
				__( 'Page views', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Both count one view of one page.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Visitors', 'honest-analytics' ),
				__( 'Unique visitors', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'Independent Analytics recognises a returning visitor by a stored signature. This plugin uses a code destroyed every night, so the two will not always agree on who is new.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Sessions', 'honest-analytics' ),
				__( 'Sessions', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'Both group a visit into a session, but they do not end one at the same moment, so the counts will differ slightly.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Referrers', 'honest-analytics' ),
				__( 'Traffic sources', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'Only the referring website is kept, never the full address of the page somebody came from.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Countries', 'honest-analytics' ),
				__( 'Locations', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Both record a country and nothing more precise.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Devices, browsers and platforms', 'honest-analytics' ),
				__( 'Devices', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Kept as categories, with names made to match the ones already in your reports.', 'honest-analytics' )
			),
		];
	}

	/**
	 * What to say before this import starts.
	 *
	 * @return string[]
	 */
	public function notes(): array {
		return [
			__( 'Page views and historical trends can be carried across, along with the other compatible metrics.', 'honest-analytics' ),
			__( 'Independent Analytics uses its own method for identifying visitors and sessions, so those numbers may differ slightly after switching.', 'honest-analytics' ),
			__( 'Nothing in Independent Analytics is changed or removed, and it does not need to stay active for its history to be imported.', 'honest-analytics' ),
		];
	}

	/**
	 * Where to begin.
	 *
	 * @param ImportConfiguration $configuration What the user chose.
	 *
	 * @return array<string,mixed>
	 */
	public function start( ImportConfiguration $configuration ): array {
		return [ 'date' => $configuration->dateFrom ];
	}

	/**
	 * Import a run of days.
	 *
	 * @param ImportJob $job    The job.
	 * @param callable  $writer Hands a day to the sink.
	 */
	public function processBatch( ImportJob $job, callable $writer ): ImportBatchResult {
		$map = $this->map();

		if ( null === $map['viewsDate'] ) {
			return ImportBatchResult::complete( $job->cursor );
		}

		if ( empty( $job->cursor['tzLogged'] ) ) {
			ImportLog::write(
				$job->id,
				ImportLog::INFO,
				'Reading stored times as the site\'s own clock.',
				[ 'timezone' => $this->storageTimezone()->getName() ]
			);
		}

		$date     = (string) ( $job->cursor['date'] ?? $job->configuration->dateFrom );
		$deadline = microtime( true ) + ImportRunner::batchSeconds();

		$days      = 0;
		$processed = 0;

		for ( $i = 0; $i < self::DAYS_PER_BATCH; $i++ ) {
			if ( $date > $job->configuration->dateTo ) {
				// Carrying the counts, not discarding them. This branch and the
				// one after the loop are the same answer reached two ways, and
				// an import short enough to finish inside a single batch used to
				// report that it had read nothing at all.
				return new ImportBatchResult(
					processed: $processed,
					daysDone: $days,
					finished: true,
					cursor: [
						'date'     => $date,
						'tzLogged' => true,
					]
				);
			}

			$bucket = new DayBucket( $date );

			$processed += $this->fillDay( $bucket, $date, $map );

			$writer( $bucket );

			++$days;

			$date = gmdate( 'Y-m-d', (int) strtotime( $date . ' +1 day UTC' ) );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$cursor = [
			'date'     => $date,
			'tzLogged' => true,
		];

		if ( $date > $job->configuration->dateTo ) {
			return new ImportBatchResult(
				processed: $processed,
				daysDone: $days,
				finished: true,
				cursor: $cursor
			);
		}

		return new ImportBatchResult( processed: $processed, daysDone: $days, cursor: $cursor );
	}

	/**
	 * Nothing held between batches.
	 *
	 * @param ImportJob $job The job.
	 */
	public function cleanUp( ImportJob $job ): void {
		unset( $job );
	}

	/**
	 * Read one day.
	 *
	 * @param DayBucket                 $bucket Day to fill.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillDay( DayBucket $bucket, string $date, array $map ): int {
		[ $start, $end ] = $this->dayBounds( $date );

		$read  = $this->fillViews( $bucket, $start, $end, $map );
		$read += $this->fillSessions( $bucket, $start, $end, $map );

		return $read;
	}

	/**
	 * Page views for one day.
	 *
	 * Ranged on the timestamp rather than wrapped in DATE(), so the index on
	 * the column is actually used. On a table with millions of views that is
	 * the difference between a batch and an afternoon.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $start  Inclusive lower bound.
	 * @param string                    $end    Exclusive upper bound.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillViews( DayBucket $bucket, string $start, string $end, array $map ): int {
		global $wpdb;

		$table = SourceSchema::table( $this->viewsTable() );
		$when  = $this->column( (string) $map['viewsDate'] );

		if ( null === $map['viewsResource'] ) {
			return 0;
		}

		$resource = $this->column( (string) $map['viewsResource'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `$resource` AS resource, COUNT(*) AS views FROM `$table`
				WHERE `$when` >= %s AND `$when` < %s GROUP BY `$resource`",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$read = 0;

		foreach ( (array) $rows as $row ) {
			$views = (int) $row['views'];
			$read += $views;

			// Every view read counts towards the day, including one whose page
			// cannot be named. It is a page of this site that has since been
			// deleted, not somebody else's page: we know the view happened, and
			// dropping it would quietly understate the day. The per-page rows
			// therefore sum to slightly less than the day's total, which is the
			// honest shape of "we know how many, not all of where".
			$bucket->addTotals( 0, 0, $views );

			$path = $this->resourcePath( $row['resource'] );

			if ( '' === $path ) {
				continue;
			}

			$bucket->addPage( $path, $views );
		}

		return $read;
	}

	/**
	 * Sessions, visitors, referrers, countries and devices for one day.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $start  Inclusive lower bound.
	 * @param string                    $end    Exclusive upper bound.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillSessions( DayBucket $bucket, string $start, string $end, array $map ): int {
		if ( null === $map['sessionsDate'] ) {
			return 0;
		}

		global $wpdb;

		$table   = SourceSchema::table( $this->sessionsTable() );
		$when    = $this->column( (string) $map['sessionsDate'] );
		$visitor = null !== $map['sessionVisitor'] ? $this->column( (string) $map['sessionVisitor'] ) : null;
		$bounced = null !== $map['sessionBounced'] ? $this->column( (string) $map['sessionBounced'] ) : null;

		$select = [ 'COUNT(*) AS sessions' ];

		$select[] = null !== $visitor ? "COUNT(DISTINCT `$visitor`) AS visitors" : 'COUNT(*) AS visitors';
		$select[] = null !== $bounced ? "COALESCE(SUM(`$bounced`),0) AS bounces" : '0 AS bounces';

		$columns = implode( ', ', $select );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and every value is a placeholder.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT $columns FROM `$table` WHERE `$when` >= %s AND `$when` < %s",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$sessions = (int) ( $totals['sessions'] ?? 0 );

		if ( 0 === $sessions ) {
			return 0;
		}

		$bucket->addTotals(
			$sessions,
			(int) ( $totals['visitors'] ?? 0 ),
			0,
			(int) ( $totals['bounces'] ?? 0 )
		);

		$this->fillSessionDimension( $bucket, $start, $end, $table, $when, $map );

		return $sessions;
	}

	/**
	 * Referrers, countries and devices, from the sessions table.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $start  Lower bound.
	 * @param string                    $end    Upper bound.
	 * @param string                    $table  Prefixed sessions table.
	 * @param string                    $when   Timestamp column.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillSessionDimension( DayBucket $bucket, string $start, string $end, string $table, string $when, array $map ): void {
		global $wpdb;

		if ( null !== $map['sessionReferrer'] ) {
			$referrer = $this->column( (string) $map['sessionReferrer'] );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and every value is a placeholder.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `$referrer` AS referrer, COUNT(*) AS sessions FROM `$table`
					WHERE `$when` >= %s AND `$when` < %s GROUP BY `$referrer`",
					$start,
					$end
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$classifier = Plugin::instance()->channels();

			foreach ( (array) $rows as $row ) {
				$raw  = trim( (string) ( $row['referrer'] ?? '' ) );
				$host = '' !== $raw ? $classifier->host( $raw ) : null;

				$bucket->addSource( $classifier->classify( $raw ), null !== $host ? $host : '', (int) $row['sessions'] );
			}
		}

		if ( null !== $map['sessionCountry'] ) {
			$country = $this->column( (string) $map['sessionCountry'] );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and every value is a placeholder.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `$country` AS code, COUNT(*) AS sessions FROM `$table`
					WHERE `$when` >= %s AND `$when` < %s GROUP BY `$country`",
					$start,
					$end
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $rows as $row ) {
				$code = SourceValues::country( (string) ( $row['code'] ?? '' ) );

				if ( '' !== $code ) {
					$bucket->addCountry( $code, (int) $row['sessions'] );
				}
			}
		}

		$this->fillDevices( $bucket, $start, $end, $table, $when, $map );
	}

	/**
	 * Browsers, platforms and device types.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $start  Lower bound.
	 * @param string                    $end    Upper bound.
	 * @param string                    $table  Prefixed sessions table.
	 * @param string                    $when   Timestamp column.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillDevices( DayBucket $bucket, string $start, string $end, string $table, string $when, array $map ): void {
		$select = [];
		$group  = [];

		foreach ( [
			'sessionBrowser' => 'browser',
			'sessionOs'      => 'platform',
			'sessionDevice'  => 'device',
		] as $key => $alias ) {
			if ( null === $map[ $key ] ) {
				continue;
			}

			$column   = $this->column( (string) $map[ $key ] );
			$select[] = "`$column` AS $alias";
			$group[]  = "`$column`";
		}

		if ( [] === $select ) {
			return;
		}

		global $wpdb;

		$columns = implode( ', ', $select );
		$by      = implode( ', ', $group );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $columns, COUNT(*) AS sessions FROM `$table`
				WHERE `$when` >= %s AND `$when` < %s GROUP BY $by",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$platform = SourceValues::platform( (string) ( $row['platform'] ?? '' ) );

			$bucket->addDevice(
				SourceValues::browser( (string) ( $row['browser'] ?? '' ) ),
				0,
				$platform,
				SourceValues::deviceType( (string) ( $row['device'] ?? '' ), $platform ),
				(int) $row['sessions']
			);
		}
	}

	/**
	 * The normalised path for one of Independent Analytics' resources.
	 *
	 * Resolved through the resources table where there is one, and through
	 * WordPress itself where the resource is a post and the table only stores
	 * an object id. Cached for the request, because a day's views hit the same
	 * few dozen resources over and over.
	 *
	 * @param mixed $resourceId Whatever the views table stored.
	 */
	private function resourcePath( mixed $resourceId ): string {
		$id = (int) $resourceId;

		if ( isset( $this->resourcePaths[ $id ] ) ) {
			return $this->resourcePaths[ $id ];
		}

		$map  = $this->map();
		$path = '';

		if ( null !== $map['resourceId'] ) {
			global $wpdb;

			$table  = SourceSchema::table( self::T_RESOURCES );
			$key    = $this->column( (string) $map['resourceId'] );
			$select = [];

			foreach ( [
				'resourceUrl'    => 'url',
				'resourceObject' => 'objectId',
				'resourceType'   => 'type',
			] as $column => $alias ) {
				if ( null !== $map[ $column ] ) {
					$select[] = '`' . $this->column( (string) $map[ $column ] ) . "` AS $alias";
				}
			}

			if ( [] !== $select ) {
				$columns = implode( ', ', $select );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and the value is a placeholder.
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT $columns FROM `$table` WHERE `$key` = %d", $id ),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$path = $this->pathFromResource( is_array( $row ) ? $row : [] );
			}
		}

		$this->resourcePaths[ $id ] = $path;

		return $path;
	}

	/**
	 * Turn a resource row into a path.
	 *
	 * @param array<string,mixed> $row Resource row.
	 */
	private function pathFromResource( array $row ): string {
		$urls = $this->urls();
		$raw  = trim( (string) ( $row['url'] ?? '' ) );

		if ( '' !== $raw ) {
			return $urls->normalise( $raw );
		}

		$objectId = (int) ( $row['objectId'] ?? 0 );

		if ( $objectId > 0 ) {
			$permalink = get_permalink( $objectId );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $urls->normalise( $permalink );
			}
		}

		// A resource whose page no longer exists and whose URL was never stored
		// cannot be placed. Its views are counted in the day's total but not
		// against a page, which is honest: we know they happened, not where.
		return '';
	}

	/**
	 * The instants that bound one calendar day, as the source would have stored them.
	 *
	 * Two timezones, and they are not the same question. `$date` is a *site*
	 * calendar day, because that is what every rollup row in this plugin is
	 * keyed by; the bounds have to be written in the *storage* zone, because
	 * that is what the values in the source's column are.
	 *
	 * Both used to be the storage zone, so the conversion cancelled and the
	 * filter below could not change the answer. On an install that stored UTC
	 * behind a Sydney site, every evening hit was filed under the previous day
	 * and there was no way to say so.
	 *
	 * @param string $date Y-m-d, site-local.
	 *
	 * @return array{0:string,1:string} Inclusive lower bound, exclusive upper bound.
	 */
	private function dayBounds( string $date ): array {
		$storage = $this->storageTimezone();
		$start   = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Timezone::site() );

		if ( false === $start ) {
			return [ $date . ' 00:00:00', $date . ' 23:59:59' ];
		}

		$end = $start->modify( '+1 day' );

		return [
			$start->setTimezone( $storage )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $storage )->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * The timezone the source's timestamps are in.
	 *
	 * The site's own, by default. WordPress plugins conventionally store local
	 * time, and on that default the conversion in `dayBounds()` is the identity
	 * - the boundaries are read exactly as they were written. A site that knows
	 * better can say so, and saying so now actually moves the window: until
	 * this was fixed the filter changed nothing but a log line.
	 */
	private function storageTimezone(): DateTimeZone {
		/**
		 * Filters the timezone Independent Analytics timestamps are read in.
		 *
		 * Return 'UTC' for an install that stored UTC rather than local time.
		 *
		 * @param string $timezone A timezone name.
		 */
		$name = (string) apply_filters( 'honest_analytics_import_iawp_timezone', Timezone::site()->getName() );

		try {
			return new DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return Timezone::site();
		}
	}

	/**
	 * Whether either shape of Independent Analytics is here.
	 */
	private function present(): bool {
		return SourceSchema::hasTable( self::T_VIEWS ) || SourceSchema::hasTable( self::T_LEGACY );
	}

	/**
	 * Whichever table holds the views.
	 */
	private function viewsTable(): string {
		return SourceSchema::hasTable( self::T_VIEWS ) ? self::T_VIEWS : self::T_LEGACY;
	}

	/**
	 * Whichever table holds the sessions.
	 *
	 * The older shape has no separate sessions table; its single visits table
	 * carries the session columns too, so it plays both parts.
	 */
	private function sessionsTable(): string {
		return SourceSchema::hasTable( self::T_SESSIONS ) ? self::T_SESSIONS : self::T_LEGACY;
	}

	/**
	 * Which column holds each thing on this site.
	 *
	 * @return array<string,string|null>
	 */
	private function map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$views    = $this->viewsTable();
		$sessions = $this->sessionsTable();

		$this->map = [
			'viewsDate'       => SourceSchema::firstColumn( $views, [ 'viewed_at', 'created_at', 'date' ] ),
			'viewsResource'   => SourceSchema::firstColumn( $views, [ 'resource_id', 'page_id', 'post_id' ] ),
			'sessionsDate'    => SourceSchema::firstColumn( $sessions, [ 'created_at', 'started_at', 'viewed_at', 'date' ] ),
			'sessionVisitor'  => SourceSchema::firstColumn( $sessions, [ 'visitor_id', 'visitor', 'signature' ] ),
			'sessionBounced'  => SourceSchema::firstColumn( $sessions, [ 'bounced', 'is_bounce' ] ),
			'sessionReferrer' => SourceSchema::firstColumn( $sessions, [ 'referrer', 'referer', 'initial_referrer' ] ),
			'sessionCountry'  => SourceSchema::firstColumn( $sessions, [ 'country', 'country_code', 'location' ] ),
			'sessionDevice'   => SourceSchema::firstColumn( $sessions, [ 'device', 'device_type' ] ),
			'sessionBrowser'  => SourceSchema::firstColumn( $sessions, [ 'browser', 'agent' ] ),
			'sessionOs'       => SourceSchema::firstColumn( $sessions, [ 'os', 'platform', 'operating_system' ] ),
			'resourceId'      => SourceSchema::firstColumn( self::T_RESOURCES, [ 'resource_id', 'id' ] ),
			'resourceUrl'     => SourceSchema::firstColumn( self::T_RESOURCES, [ 'url', 'permalink', 'slug', 'uri' ] ),
			'resourceObject'  => SourceSchema::firstColumn( self::T_RESOURCES, [ 'object_id', 'post_id' ] ),
			'resourceType'    => SourceSchema::firstColumn( self::T_RESOURCES, [ 'resource_type', 'type' ] ),
		];

		// The older single-table shape stores the page directly rather than
		// through a resources table, so the views row is its own resource.
		if ( ! SourceSchema::hasTable( self::T_RESOURCES ) && SourceSchema::hasTable( self::T_LEGACY ) ) {
			$this->map['resourceId']     = SourceSchema::firstColumn( self::T_LEGACY, [ 'page_id', 'post_id' ] );
			$this->map['resourceUrl']    = SourceSchema::firstColumn( self::T_LEGACY, [ 'url', 'uri' ] );
			$this->map['resourceObject'] = SourceSchema::firstColumn( self::T_LEGACY, [ 'page_id', 'post_id' ] );
		}

		return $this->map;
	}

	/**
	 * The earliest and latest days the source holds.
	 *
	 * @return array{0:string|null,1:string|null}
	 */
	private function range(): array {
		$map = $this->map();

		if ( null === $map['viewsDate'] ) {
			return [ null, null ];
		}

		global $wpdb;

		$table = SourceSchema::table( $this->viewsTable() );
		$when  = $this->column( (string) $map['viewsDate'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema.
		$row = $wpdb->get_row( "SELECT MIN(`$when`) AS dateFrom, MAX(`$when`) AS dateTo FROM `$table`", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return [
			$this->asDate( (string) ( $row['dateFrom'] ?? '' ) ),
			$this->asDate( (string) ( $row['dateTo'] ?? '' ) ),
		];
	}

	/**
	 * The calendar day part of a stored timestamp.
	 *
	 * Taken as written rather than converted, for the reason in the class
	 * docblock: the timestamp's own day is the day it is filed under.
	 *
	 * @param string $value Raw column value.
	 */
	private function asDate( string $value ): ?string {
		$value = trim( $value );

		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $matches ) && '0000-00-00' !== $matches[1] ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * A column name safe to interpolate.
	 *
	 * @param string $column Column name from the source's own schema.
	 */
	private function column( string $column ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', $column );
	}

	/**
	 * The URL mapper, built once.
	 */
	private function urls(): SourceUrl {
		static $urls = null;

		if ( null === $urls ) {
			$urls = new SourceUrl( Plugin::instance()->paths() );
		}

		return $urls;
	}
}
