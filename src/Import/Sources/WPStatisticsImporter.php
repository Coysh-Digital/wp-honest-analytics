<?php
/**
 * Importing from WP Statistics.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Sources;

use DateTimeImmutable;
use HonestAnalytics\Import\Coverage;
use HonestAnalytics\Import\DayBucket;
use HonestAnalytics\Import\DetectionResult;
use HonestAnalytics\Import\ImportBatchResult;
use HonestAnalytics\Import\ImportConfiguration;
use HonestAnalytics\Import\ImporterInterface;
use HonestAnalytics\Import\ImportJob;
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
 * Reads WP Statistics' tables. Never writes to them.
 *
 * WP Statistics keeps a row per page per day in `statistics_pages` and a row
 * per visitor per day in `statistics_visitor`, which is a good fit: both are
 * already daily aggregates, so nothing has to be re-bucketed and no timezone
 * has to be guessed at. The dates it stores are the dates it meant, and they
 * are kept as they are.
 *
 * The column names have moved between versions - `last_counter` became `date`
 * in some, `location` became `country` in others - so every column is looked up
 * rather than assumed. A site whose WP Statistics was deleted years ago, tables
 * left behind, imports exactly as well as one still running it.
 */
final class WPStatisticsImporter implements ImporterInterface {

	private const T_PAGES      = 'statistics_pages';
	private const T_VISITOR    = 'statistics_visitor';
	private const T_VISIT      = 'statistics_visit';
	private const T_HISTORICAL = 'statistics_historical';

	/** Days per batch, before the clock is consulted. */
	private const DAYS_PER_BATCH = 40;

	/**
	 * The column each thing lives in on this site, worked out once.
	 *
	 * @var array<string,string|null>|null
	 */
	private ?array $map = null;

	public function id(): string {
		return ImportSource::WP_STATISTICS;
	}

	public function name(): string {
		return __( 'WP Statistics', 'honest-analytics' );
	}

	/**
	 * Is there any WP Statistics data on this site?
	 */
	public function detect(): DetectionResult {
		if ( ! SourceSchema::hasTable( self::T_PAGES ) && ! SourceSchema::hasTable( self::T_VISITOR ) ) {
			return DetectionResult::none( __( 'Not detected on this website.', 'honest-analytics' ) );
		}

		$map = $this->map();

		if ( null === $map['pagesDate'] && null === $map['visitorDate'] ) {
			return new DetectionResult(
				DetectionResult::UNSUPPORTED,
				__( 'WP Statistics is here, but its data is stored in a layout this version does not recognise.', 'honest-analytics' )
			);
		}

		[ $from, $to ] = $this->range();

		if ( null === $from || null === $to ) {
			return new DetectionResult(
				DetectionResult::EMPTY_SOURCE,
				__( 'WP Statistics is installed, but there is no history in it yet.', 'honest-analytics' )
			);
		}

		return new DetectionResult(
			DetectionResult::AVAILABLE,
			__( 'Historical data is ready to import.', 'honest-analytics' ),
			$from,
			$to,
			SourceSchema::approximateRows( self::T_PAGES ) + SourceSchema::approximateRows( self::T_VISITOR ),
			true,
			$this->notes()
		);
	}

	/**
	 * What an import would bring across.
	 */
	public function inspect(): ImportSummary {
		[ $from, $to ] = $this->range();

		$totals     = [];
		$dimensions = [ __( 'Page views', 'honest-analytics' ) ];
		$excluded   = [];
		$map        = $this->map();

		if ( null !== $map['pagesDate'] ) {
			$totals[ __( 'page views', 'honest-analytics' ) ] = $this->sum( self::T_PAGES, (string) $map['pagesCount'], (string) $map['pagesDate'] );
		}

		if ( null !== $map['visitorDate'] ) {
			$totals[ __( 'visits', 'honest-analytics' ) ] = $this->countRows( self::T_VISITOR, (string) $map['visitorDate'] );

			$dimensions[] = __( 'Visitors', 'honest-analytics' );
		}

		if ( null !== $map['visitorReferrer'] ) {
			$dimensions[] = __( 'Referrers', 'honest-analytics' );
		}

		if ( null !== $map['visitorCountry'] ) {
			$dimensions[] = __( 'Countries', 'honest-analytics' );
		}

		if ( null !== $map['visitorBrowser'] || null !== $map['visitorDevice'] ) {
			$dimensions[] = __( 'Devices and browsers', 'honest-analytics' );
		}

		if ( SourceSchema::hasTable( self::T_HISTORICAL ) ) {
			// WP Statistics keeps running totals from before a purge in a table
			// with no dates on it. There is no day to file them under, so they
			// stay where they are rather than being invented into one.
			$excluded[] = __( 'Pre-purge totals, which WP Statistics stores without dates', 'honest-analytics' );
		}

		$excluded[] = __( 'IP addresses and anything else that identifies a person', 'honest-analytics' );

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
			$excluded,
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
	 * How each of WP Statistics' figures becomes one of ours.
	 *
	 * @return MetricMapping[]
	 */
	public function mappings(): array {
		return [
			new MetricMapping(
				__( 'Page views', 'honest-analytics' ),
				__( 'Page views', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Both count one view of one page.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Visitors', 'honest-analytics' ),
				__( 'Unique visitors', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'WP Statistics recognises a returning visitor by their address and browser. This plugin uses a code that is destroyed every night, so the two will not always agree on who is new.', 'honest-analytics' )
			),
			new MetricMapping(
				__( 'Visits', 'honest-analytics' ),
				__( 'Sessions', 'honest-analytics' ),
				MetricMapping::APPROXIMATE,
				__( 'WP Statistics keeps one row per person per day. This plugin ends a session after thirty minutes of inactivity, so somebody who reads in the morning and again at night is one visit there and two sessions here.', 'honest-analytics' )
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
				__( 'Browsers and platforms', 'honest-analytics' ),
				__( 'Devices', 'honest-analytics' ),
				MetricMapping::EXACT,
				__( 'Browser, operating system and device type, kept as categories.', 'honest-analytics' )
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
			__( 'We will preserve the historical analytics that map reliably to this plugin.', 'honest-analytics' ),
			__( 'WP Statistics and this plugin identify visitors and visits differently, so historical visitor totals may not perfectly match the analytics collected after the migration.', 'honest-analytics' ),
			__( 'Nothing in WP Statistics is changed or removed. Its data stays exactly where it is.', 'honest-analytics' ),
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
	 * The cursor is the next date to do. It advances only once a day has been
	 * handed to the writer, so a batch killed halfway through leaves that day
	 * to be done again - which is free, because writing a day replaces it.
	 *
	 * @param ImportJob $job    The job.
	 * @param callable  $writer Hands a day to the sink.
	 */
	public function processBatch( ImportJob $job, callable $writer ): ImportBatchResult {
		$map = $this->map();

		if ( null === $map['pagesDate'] && null === $map['visitorDate'] ) {
			return ImportBatchResult::complete( $job->cursor );
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
					cursor: [ 'date' => $date ]
				);
			}

			$bucket = new DayBucket( $date );

			$processed += $this->fillDay( $bucket, $date, $map );

			$writer( $bucket );

			++$days;

			$date = $this->nextDay( $date );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( $date > $job->configuration->dateTo ) {
			return new ImportBatchResult(
				processed: $processed,
				daysDone: $days,
				finished: true,
				cursor: [ 'date' => $date ]
			);
		}

		return new ImportBatchResult(
			processed: $processed,
			daysDone: $days,
			cursor: [ 'date' => $date ]
		);
	}

	/**
	 * Nothing to tidy: this importer holds nothing between batches.
	 *
	 * @param ImportJob $job The job.
	 */
	public function cleanUp( ImportJob $job ): void {
		unset( $job );
	}

	/**
	 * Read one day out of WP Statistics.
	 *
	 * @param DayBucket                 $bucket Day to fill.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 *
	 * @return int Source rows read.
	 */
	private function fillDay( DayBucket $bucket, string $date, array $map ): int {
		$read  = 0;
		$read += $this->fillPages( $bucket, $date, $map );
		$read += $this->fillVisitors( $bucket, $date, $map );

		return $read;
	}

	/**
	 * Per-page views for one day.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillPages( DayBucket $bucket, string $date, array $map ): int {
		if ( null === $map['pagesDate'] || null === $map['pagesUri'] ) {
			return 0;
		}

		global $wpdb;

		$table = SourceSchema::table( self::T_PAGES );
		$uri   = $this->column( (string) $map['pagesUri'] );
		$when  = $this->column( (string) $map['pagesDate'] );
		$count = null !== $map['pagesCount'] ? 'COALESCE(SUM(`' . $this->column( (string) $map['pagesCount'] ) . '`),0)' : 'COUNT(*)';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and the value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `$uri` AS uri, $count AS views FROM `$table` WHERE `$when` = %s GROUP BY `$uri`",
				$date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$urls  = $this->urls();
		$read  = 0;
		$views = 0;

		foreach ( (array) $rows as $row ) {
			++$read;

			$path = $urls->normalise( (string) $row['uri'] );

			if ( '' === $path ) {
				continue;
			}

			$bucket->addPage( $path, (int) $row['views'] );

			$views += (int) $row['views'];
		}

		if ( $views > 0 ) {
			$bucket->addTotals( 0, 0, $views );
		}

		return $read;
	}

	/**
	 * Visitors, referrers, countries and devices for one day.
	 *
	 * Read as five grouped queries against one indexed date rather than by
	 * pulling the day's visitor rows into PHP. A busy site has tens of thousands
	 * of rows a day and the database is much better at counting them than we are.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 */
	private function fillVisitors( DayBucket $bucket, string $date, array $map ): int {
		if ( null === $map['visitorDate'] ) {
			return 0;
		}

		global $wpdb;

		$table = SourceSchema::table( self::T_VISITOR );
		$when  = $this->column( (string) $map['visitorDate'] );
		$read  = 0;

		// One row per visitor per day, so counting rows counts visitors.
		// `hits` is that visitor's page views, where the column exists.
		$hits = null !== $map['visitorHits'] ? 'COALESCE(SUM(`' . $this->column( (string) $map['visitorHits'] ) . '`),0)' : '0';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and the value is a placeholder.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS visitors, $hits AS hits FROM `$table` WHERE `$when` = %s",
				$date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$visitors = (int) ( $totals['visitors'] ?? 0 );

		if ( 0 === $visitors ) {
			return 0;
		}

		$read += $visitors;

		// Visitors and sessions are the same number here, and both are marked
		// approximate for the same reason: WP Statistics has no session concept,
		// so its per-person-per-day row is the nearest thing to either.
		$bucket->addTotals( $visitors, $visitors, (int) ( $totals['hits'] ?? 0 ) );

		$this->fillReferrers( $bucket, $date, $map, $table, $when );
		$this->fillCountries( $bucket, $date, $map, $table, $when );
		$this->fillDevices( $bucket, $date, $map, $table, $when );

		return $read;
	}

	/**
	 * Referring sites for one day.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 * @param string                    $table  Prefixed visitor table.
	 * @param string                    $when   Date column.
	 */
	private function fillReferrers( DayBucket $bucket, string $date, array $map, string $table, string $when ): void {
		if ( null === $map['visitorReferrer'] ) {
			return;
		}

		global $wpdb;

		$referrer = $this->column( (string) $map['visitorReferrer'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and the value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `$referrer` AS referrer, COUNT(*) AS sessions FROM `$table` WHERE `$when` = %s GROUP BY `$referrer`",
				$date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$classifier = Plugin::instance()->channels();

		foreach ( (array) $rows as $row ) {
			$raw = trim( (string) ( $row['referrer'] ?? '' ) );

			// Only the host is kept. The rest of a referrer URL routinely holds
			// search terms, session tokens and private paths, and this plugin
			// does not store any of that - imported or otherwise.
			$host = '' !== $raw ? $classifier->host( $raw ) : null;

			$bucket->addSource(
				$classifier->classify( $raw ),
				null !== $host ? $host : '',
				(int) $row['sessions']
			);
		}
	}

	/**
	 * Countries for one day.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 * @param string                    $table  Prefixed visitor table.
	 * @param string                    $when   Date column.
	 */
	private function fillCountries( DayBucket $bucket, string $date, array $map, string $table, string $when ): void {
		if ( null === $map['visitorCountry'] ) {
			return;
		}

		global $wpdb;

		$country = $this->column( (string) $map['visitorCountry'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and the value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `$country` AS code, COUNT(*) AS sessions FROM `$table` WHERE `$when` = %s GROUP BY `$country`",
				$date
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

	/**
	 * Browsers, platforms and device types for one day.
	 *
	 * @param DayBucket                 $bucket Day.
	 * @param string                    $date   Y-m-d.
	 * @param array<string,string|null> $map    Column map.
	 * @param string                    $table  Prefixed visitor table.
	 * @param string                    $when   Date column.
	 */
	private function fillDevices( DayBucket $bucket, string $date, array $map, string $table, string $when ): void {
		$select = [];
		$group  = [];

		foreach ( [
			'visitorBrowser'  => 'browser',
			'visitorVersion'  => 'version',
			'visitorPlatform' => 'platform',
			'visitorDevice'   => 'device',
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema and the value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $columns, COUNT(*) AS sessions FROM `$table` WHERE `$when` = %s GROUP BY $by",
				$date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$platform = SourceValues::platform( (string) ( $row['platform'] ?? '' ) );

			$bucket->addDevice(
				SourceValues::browser( (string) ( $row['browser'] ?? '' ) ),
				SourceValues::majorVersion( (string) ( $row['version'] ?? '' ) ),
				$platform,
				SourceValues::deviceType( (string) ( $row['device'] ?? '' ), $platform ),
				(int) $row['sessions']
			);
		}
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

		$this->map = [
			// `date` in current versions, `last_counter` in older ones.
			'pagesDate'       => SourceSchema::firstColumn( self::T_PAGES, [ 'date', 'last_counter' ] ),
			'pagesUri'        => SourceSchema::firstColumn( self::T_PAGES, [ 'uri', 'page_uri', 'url' ] ),
			'pagesCount'      => SourceSchema::firstColumn( self::T_PAGES, [ 'count', 'hits', 'views' ] ),
			'visitorDate'     => SourceSchema::firstColumn( self::T_VISITOR, [ 'last_counter', 'date', 'visit_date' ] ),
			'visitorReferrer' => SourceSchema::firstColumn( self::T_VISITOR, [ 'referred', 'referrer', 'referer' ] ),
			'visitorCountry'  => SourceSchema::firstColumn( self::T_VISITOR, [ 'location', 'country', 'country_code' ] ),
			'visitorBrowser'  => SourceSchema::firstColumn( self::T_VISITOR, [ 'agent', 'browser' ] ),
			'visitorVersion'  => SourceSchema::firstColumn( self::T_VISITOR, [ 'version', 'browser_version' ] ),
			'visitorPlatform' => SourceSchema::firstColumn( self::T_VISITOR, [ 'platform', 'os' ] ),
			'visitorDevice'   => SourceSchema::firstColumn( self::T_VISITOR, [ 'device', 'device_type' ] ),
			'visitorHits'     => SourceSchema::firstColumn( self::T_VISITOR, [ 'hits' ] ),
			'visitDate'       => SourceSchema::firstColumn( self::T_VISIT, [ 'last_counter', 'date' ] ),
			'visitCount'      => SourceSchema::firstColumn( self::T_VISIT, [ 'visit', 'visits' ] ),
		];

		return $this->map;
	}

	/**
	 * The earliest and latest dates WP Statistics holds.
	 *
	 * @return array{0:string|null,1:string|null}
	 */
	private function range(): array {
		$map    = $this->map();
		$ranges = [];

		if ( null !== $map['pagesDate'] ) {
			$ranges[] = $this->minMax( self::T_PAGES, (string) $map['pagesDate'] );
		}

		if ( null !== $map['visitorDate'] ) {
			$ranges[] = $this->minMax( self::T_VISITOR, (string) $map['visitorDate'] );
		}

		$from = null;
		$to   = null;

		foreach ( $ranges as $range ) {
			if ( null === $range[0] ) {
				continue;
			}

			$from = null === $from || $range[0] < $from ? $range[0] : $from;
			$to   = null === $to || $range[1] > $to ? $range[1] : $to;
		}

		return [ $from, $to ];
	}

	/**
	 * The earliest and latest value of a date column.
	 *
	 * @param string $table  Unprefixed table.
	 * @param string $column Date column.
	 *
	 * @return array{0:string|null,1:string|null}
	 */
	private function minMax( string $table, string $column ): array {
		global $wpdb;

		$name = SourceSchema::table( $table );
		$date = $this->column( $column );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema.
		$row = $wpdb->get_row( "SELECT MIN(`$date`) AS dateFrom, MAX(`$date`) AS dateTo FROM `$name`", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$from = $this->asDate( (string) ( $row['dateFrom'] ?? '' ) );
		$to   = $this->asDate( (string) ( $row['dateTo'] ?? '' ) );

		return [ $from, $to ];
	}

	/**
	 * A `Y-m-d` date, or null.
	 *
	 * WP Statistics stores dates, not moments, so the day it recorded is the
	 * day that is kept. Reinterpreting it in another timezone would move a
	 * year of traffic one day sideways for no reason.
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
	 * The sum of a column, ignoring rows with no usable date.
	 *
	 * @param string $table  Unprefixed table.
	 * @param string $column Column to sum.
	 * @param string $date   Date column.
	 */
	private function sum( string $table, string $column, string $date ): int {
		global $wpdb;

		$name = SourceSchema::table( $table );
		$col  = $this->column( $column );
		$when = $this->column( $date );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema.
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(`$col`),0) FROM `$name` WHERE `$when` IS NOT NULL" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * How many rows a table has, counted for real.
	 *
	 * @param string $table Unprefixed table.
	 * @param string $date  Date column.
	 */
	private function countRows( string $table, string $date ): int {
		global $wpdb;

		$name = SourceSchema::table( $table );
		$when = $this->column( $date );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Another plugin's table, read only; identifiers are validated column names from its own schema.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$name` WHERE `$when` IS NOT NULL" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A column name safe to interpolate.
	 *
	 * Every name here came from `SHOW COLUMNS` on the source's own table, so it
	 * is already the database's own word for something. This strips anything
	 * that is not a plain identifier anyway, because a column name reaching SQL
	 * unquoted deserves belt and braces whatever its provenance.
	 *
	 * @param string $column Column name.
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

	/**
	 * The day after a date.
	 *
	 * @param string $date Y-m-d.
	 */
	private function nextDay( string $date ): string {
		$days = Coverage::days( $date, $date );

		if ( [] === $days ) {
			return $date;
		}

		return gmdate( 'Y-m-d', (int) strtotime( $date . ' +1 day UTC' ) );
	}
}
