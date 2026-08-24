<?php
/**
 * Writing aggregates to the database.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use DateTimeZone;
use HonestAnalytics\Channels\ChannelClassifier;
use HonestAnalytics\Devices\Device;
use HonestAnalytics\Dimensions\DimensionCapper;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Import\ImportSource;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Schema\Upsert;
use HonestAnalytics\Sessions\Session;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Timezone;
use HonestAnalytics\Uniques\UniqueCounterInterface;
use HonestAnalytics\Uniques\UniqueScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class that writes to a rollup table.
 *
 * Everything arrives here already aggregated, so a busy hour is a handful of
 * upserts rather than a write per pageview.
 */
final class DbRollupSink implements RollupSinkInterface {

	private Settings $settings;
	private DimensionCapper $capper;
	private UniqueCounterInterface $counter;
	private ChannelClassifier $channels;
	private ProRollupWriter $pro;
	private DateTimeZone $timezone;

	/**
	 * @param Settings               $settings Settings.
	 * @param DimensionCapper        $capper   Dimension capper.
	 * @param UniqueCounterInterface $counter  Unique counter.
	 * @param ChannelClassifier      $channels Channel classifier.
	 * @param ProRollupWriter        $pro      Pro rollup writer.
	 * @param DateTimeZone|null      $timezone Site timezone.
	 */
	public function __construct(
		Settings $settings,
		DimensionCapper $capper,
		UniqueCounterInterface $counter,
		ChannelClassifier $channels,
		ProRollupWriter $pro,
		?DateTimeZone $timezone = null
	) {
		$this->settings = $settings;
		$this->capper   = $capper;
		$this->counter  = $counter;
		$this->channels = $channels;
		$this->pro      = $pro;
		$this->timezone = $timezone ?? Timezone::site();
	}

	/**
	 * Write a batch of aggregates.
	 *
	 * @param PageBucket[]            $buckets        Page buckets.
	 * @param Session[]               $closedSessions Finished sessions.
	 * @param InteractionBuckets|null $interactions   Everything else.
	 */
	public function flush( array $buckets, array $closedSessions = [], ?InteractionBuckets $interactions = null ): void {
		foreach ( $buckets as $bucket ) {
			$this->writePageBucket( $bucket );
		}

		$this->writeDailyUniques( $buckets );

		$this->writeSessions( $closedSessions );

		if ( null !== $interactions && ! $interactions->isEmpty() ) {
			$this->writeCrawlers( $interactions );
			$this->writePageSources( $interactions );
			$this->pro->writeInteractions( $interactions, $this->capper );
		}
	}

	/**
	 * Write one page bucket.
	 *
	 * @param PageBucket $bucket Bucket.
	 */
	private function writePageBucket( PageBucket $bucket ): void {
		$pathDimId = $this->capper->resolve( $bucket->siteId, $bucket->date, DimensionType::Path, $bucket->path );

		// `source` is part of every one of these unique keys (ADR 53), so it
		// belongs in the key the sketch is looked up by too. Left out, the
		// SELECT ... FOR UPDATE inside recordUniques() matched a *range* rather
		// than a row - a next-key lock instead of a single-row one, and on a
		// day that also holds an imported row, two rows rather than one. It was
		// only ever safe because imports write `hour = -1`.
		$keys = [
			'siteId'    => $bucket->siteId,
			'date'      => $bucket->date,
			'hour'      => $bucket->hour,
			'pathDimId' => $pathDimId,
			'source'    => ImportSource::NATIVE,
		];

		Upsert::counters(
			Tables::PAGES_ROLLUP,
			$keys,
			[
				'views'        => $bucket->views,
				'totalDwellMs' => $bucket->dwellMs,
			],
			null !== $bucket->postId ? [ 'postId' => $bucket->postId ] : [],
			[ 'postId' ]
		);

		$this->recordUniques(
			Tables::PAGES_ROLLUP,
			$keys,
			new UniqueScope( UniqueScope::KIND_PAGE, $bucket->siteId, $bucket->date, $bucket->hour, $pathDimId ),
			$bucket->visitorHashes()
		);
	}

	/**
	 * Fold this batch's visitors into one sketch per site per day.
	 *
	 * The same hashes that go onto the per-path rows, merged again at the level
	 * the reports actually ask about. Answering "how many people came to this
	 * site this month" from the per-path rows means merging every one of them -
	 * a thirty-day range at the default dimension cap is around 191,000 rows
	 * carrying a sketch each, deserialised and merged in PHP, twice per render
	 * and four times with a comparison period.
	 *
	 * Safe to apply twice, which matters because a drain batch can be replayed:
	 * adding a hash a sketch already holds changes nothing, and the exact
	 * counter's member rows are keyed `(scopeKey, visitorHash)` and unique. It
	 * is the same property that lets the per-path rows be replayed.
	 *
	 * @param PageBucket[] $buckets Page buckets in this batch.
	 */
	private function writeDailyUniques( array $buckets ): void {
		$days = [];

		foreach ( $buckets as $bucket ) {
			$key = $bucket->siteId . '|' . $bucket->date;

			if ( ! isset( $days[ $key ] ) ) {
				$days[ $key ] = [
					'siteId' => $bucket->siteId,
					'date'   => $bucket->date,
					'hashes' => [],
				];
			}

			foreach ( $bucket->visitorHashes() as $hash ) {
				$days[ $key ]['hashes'][ $hash ] = true;
			}
		}

		foreach ( $days as $day ) {
			$keys = [
				'siteId' => $day['siteId'],
				'date'   => $day['date'],
				'source' => ImportSource::NATIVE,
			];

			// A row has to exist for the sketch to be written onto, and there
			// is no counter to add - the figure is the sketch.
			Upsert::counters( Tables::DAILY_UNIQUES, $keys, [ 'importedUniques' => 0 ] );

			$this->recordUniques(
				Tables::DAILY_UNIQUES,
				$keys,
				new UniqueScope( UniqueScope::KIND_SITE, $day['siteId'], $day['date'], UniqueScope::HOUR_DAILY ),
				array_keys( $day['hashes'] )
			);
		}
	}

	/**
	 * Write everything a batch of finished sessions contributes.
	 *
	 * Grouped before it is written, because the rows barely vary.
	 * `honest_sessions_rollup` is keyed on `(siteId, date, hour, source)`, so
	 * **every session closed in the same hour lands on the same row** - and one
	 * at a time that was a lock, a read, a deserialise, one hash added, a
	 * re-serialise and a write, per session, against a single HyperLogLog blob.
	 * At around thirty thousand sessions a day that is a quarter of a million
	 * statements to produce a few hundred rows. Sources and devices collapse
	 * the same way, by channel and by device family.
	 *
	 * Entry and exit pages are left per session: they are keyed by path, so
	 * there is nothing much to collapse, and the Pro writer keeps its own
	 * per-session contract.
	 *
	 * @param Session[] $sessions Finished sessions.
	 */
	private function writeSessions( array $sessions ): void {
		if ( [] === $sessions ) {
			return;
		}

		$totals  = [];
		$sources = [];
		$devices = [];

		foreach ( $sessions as $session ) {
			[ $date, $hour ] = Timezone::dateAndHour( $session->startedAt, $this->timezone );

			$this->collectSessionTotals( $totals, $session, $date, $hour );
			$this->collectSource( $sources, $session, $date, $hour );
			$this->collectDevice( $devices, $session, $date );

			$this->writeEntryAndExit( $session, $date, $hour );

			$this->pro->writeSession( $session, $date, $this->capper, $this->timezone );
		}

		foreach ( $totals as $group ) {
			Upsert::counters( Tables::SESSIONS_ROLLUP, $group['keys'], $group['counters'] );

			$this->recordUniques(
				Tables::SESSIONS_ROLLUP,
				$group['keys'],
				new UniqueScope( UniqueScope::KIND_SESSION, $group['keys']['siteId'], $group['keys']['date'], $group['keys']['hour'] ),
				$group['hashes']
			);
		}

		foreach ( $sources as $group ) {
			Upsert::counters( Tables::SOURCES_ROLLUP, $group['keys'], $group['counters'] );
		}

		foreach ( $devices as $group ) {
			Upsert::counters( Tables::DEVICES_ROLLUP, $group['keys'], $group['counters'] );
		}
	}

	/**
	 * Add one session to the per-hour totals.
	 *
	 * @param array<string,array{keys:array<string,mixed>,counters:array<string,int>,hashes:string[]}> $totals  Accumulator.
	 * @param Session                                                                                  $session Session.
	 * @param string                                                                                   $date    Local date.
	 * @param int                                                                                      $hour    Local hour.
	 */
	private function collectSessionTotals( array &$totals, Session $session, string $date, int $hour ): void {
		$key = $session->siteId . '|' . $date . '|' . $hour;

		if ( ! isset( $totals[ $key ] ) ) {
			$totals[ $key ] = [
				'keys'     => [
					'siteId' => $session->siteId,
					'date'   => $date,
					'hour'   => $hour,
					'source' => ImportSource::NATIVE,
				],
				'counters' => [
					'sessions'        => 0,
					'bounces'         => 0,
					'totalDurationMs' => 0,
					'totalPageviews'  => 0,
				],
				'hashes'   => [],
			];
		}

		++$totals[ $key ]['counters']['sessions'];

		$totals[ $key ]['counters']['bounces']         += $session->isBounce() ? 1 : 0;
		$totals[ $key ]['counters']['totalDurationMs'] += $session->durationMs();
		$totals[ $key ]['counters']['totalPageviews']  += $session->pageviews;
		$totals[ $key ]['hashes'][]                     = $session->visitorHash;
	}

	/**
	 * Add one session to the per-channel totals.
	 *
	 * @param array<string,array{keys:array<string,mixed>,counters:array<string,int>}> $sources Accumulator.
	 * @param Session                                                                  $session Session.
	 * @param string                                                                   $date    Local date.
	 * @param int                                                                      $hour    Local hour.
	 */
	private function collectSource( array &$sources, Session $session, string $date, int $hour ): void {
		$hasCampaign = $this->settings->enableCampaigns && [] !== $session->campaigns;
		$channel     = $this->channels->classify( $session->referrer, $hasCampaign );
		$host        = $this->channels->host( $session->referrer );

		$refHostDimId = null === $host
			? 0
			: $this->capper->resolve( $session->siteId, $date, DimensionType::ReferrerHost, $host );

		$keys = [
			'siteId'       => $session->siteId,
			'date'         => $date,
			'hour'         => $hour,
			'channel'      => $channel->value,
			'refHostDimId' => $refHostDimId,
			'source'       => ImportSource::NATIVE,
		];

		$key = implode( '|', $keys );

		if ( ! isset( $sources[ $key ] ) ) {
			$sources[ $key ] = [
				'keys'     => $keys,
				'counters' => [
					'sessions' => 0,
					'bounces'  => 0,
				],
			];
		}

		++$sources[ $key ]['counters']['sessions'];

		$sources[ $key ]['counters']['bounces'] += $session->isBounce() ? 1 : 0;
	}

	/**
	 * Add one session to the per-device totals.
	 *
	 * @param array<string,array{keys:array<string,mixed>,counters:array<string,int>}> $devices Accumulator.
	 * @param Session                                                                  $session Session.
	 * @param string                                                                   $date    Local date.
	 */
	private function collectDevice( array &$devices, Session $session, string $date ): void {
		// Already reduced, in the request that saw the user agent - see
		// HonestAnalytics\Devices\Device. There is nothing to parse here any
		// more, which is the point: the string this came from never reached
		// the spool, the sessions table or this method.
		$device = Device::fromString( $session->device );

		$keys = [
			'siteId'       => $session->siteId,
			'date'         => $date,
			'browserDimId' => $this->capper->resolve( $session->siteId, $date, DimensionType::Browser, $device->browser ),
			'browserMajor' => $device->major,
			'osDimId'      => $this->capper->resolve( $session->siteId, $date, DimensionType::Os, $device->os ),
			'deviceType'   => $device->type->value,
			'source'       => ImportSource::NATIVE,
		];

		$key = implode( '|', $keys );

		if ( ! isset( $devices[ $key ] ) ) {
			$devices[ $key ] = [
				'keys'     => $keys,
				'counters' => [ 'sessions' => 0 ],
			];
		}

		++$devices[ $key ]['counters']['sessions'];
	}

	/**
	 * Write which page a visit started on and which it left from.
	 *
	 * Bounces attach to the entry page and nowhere else, which is why a page
	 * that is rarely an entry page has no bounce rate to report rather than a
	 * misleading zero.
	 *
	 * @param Session $session Session.
	 * @param string  $date    Local date the session started.
	 * @param int     $hour    Local hour the session started.
	 */
	private function writeEntryAndExit( Session $session, string $date, int $hour ): void {
		$entryPath = '' !== $session->entryPath ? $session->entryPath : $session->lastPath;

		if ( '' === $entryPath ) {
			return;
		}

		$entryDimId = $this->capper->resolve( $session->siteId, $date, DimensionType::Path, $entryPath );

		Upsert::counters(
			Tables::PAGES_ROLLUP,
			[
				'siteId'    => $session->siteId,
				'date'      => $date,
				'hour'      => $hour,
				'pathDimId' => $entryDimId,
			],
			[
				'entrances' => 1,
				'bounces'   => $session->isBounce() ? 1 : 0,
			]
		);

		$lastPath = '' !== $session->lastPath ? $session->lastPath : $entryPath;

		[ $exitDate, $exitHour ] = Timezone::dateAndHour( $session->lastSeenAt, $this->timezone );

		// A one-page visit enters and exits on the same row. A visit that
		// crossed midnight exits on its own date, which may not be the one it
		// started on.
		$exitDimId = ( $entryPath === $lastPath && $exitDate === $date && $exitHour === $hour )
			? $entryDimId
			: $this->capper->resolve( $session->siteId, $exitDate, DimensionType::Path, $lastPath );

		Upsert::counters(
			Tables::PAGES_ROLLUP,
			[
				'siteId'    => $session->siteId,
				'date'      => $exitDate,
				'hour'      => $exitHour,
				'pathDimId' => $exitDimId,
			],
			[ 'exits' => 1 ]
		);
	}

	/**
	 * Write the crawler counts.
	 *
	 * Lite, deliberately: knowing what was excluded from your own numbers is not
	 * a premium concern.
	 *
	 * @param InteractionBuckets $interactions Interactions.
	 */
	private function writeCrawlers( InteractionBuckets $interactions ): void {
		foreach ( $interactions->crawlers as $row ) {
			Upsert::counters(
				Tables::CRAWLERS_ROLLUP,
				[
					'siteId'       => $row['siteId'],
					'date'         => $row['date'],
					'crawlerDimId' => $this->capper->resolve( $row['siteId'], $row['date'], DimensionType::Crawler, $row['name'] ),
				],
				[ 'requests' => $row['requests'] ]
			);
		}
	}

	/**
	 * Write how visits reached each page.
	 *
	 * @param InteractionBuckets $interactions Interactions.
	 */
	private function writePageSources( InteractionBuckets $interactions ): void {
		foreach ( $interactions->pageSources as $row ) {
			$channel = $this->channels->classify( $row['referrer'] );
			$host    = $this->channels->host( $row['referrer'] );

			$refHostDimId = null === $host
				? 0
				: $this->capper->resolve( $row['siteId'], $row['date'], DimensionType::ReferrerHost, $host );

			Upsert::counters(
				Tables::PAGE_SOURCES_ROLLUP,
				[
					'siteId'       => $row['siteId'],
					'date'         => $row['date'],
					'pathDimId'    => $this->capper->resolve( $row['siteId'], $row['date'], DimensionType::Path, $row['path'] ),
					'channel'      => $channel->value,
					'refHostDimId' => $refHostDimId,
				],
				[ 'views' => $row['views'] ]
			);
		}
	}

	/**
	 * Add visitor hashes to a row's unique count.
	 *
	 * @param string              $table  Unprefixed table name.
	 * @param array<string,mixed> $keys   Row key.
	 * @param UniqueScope         $scope  Scope.
	 * @param string[]            $hashes Visitor hashes.
	 */
	private function recordUniques( string $table, array $keys, UniqueScope $scope, array $hashes ): void {
		global $wpdb;

		$hashes = array_values(
			array_map( 'strval', array_filter( $hashes, [ IdentityService::class, 'isValidHash' ] ) )
		);

		if ( [] === $hashes ) {
			return;
		}

		if ( ! $this->counter->storesOnRow() ) {
			$this->counter->record( $scope, $hashes, null );

			return;
		}

		$name  = Tables::name( $table );
		$where = [];
		$args  = [];

		foreach ( $keys as $column => $value ) {
			$where[] = '`' . str_replace( '`', '', (string) $column ) . '` = ' . ( is_int( $value ) ? '%d' : '%s' );
			$args[]  = $value;
		}

		$clause = implode( ' AND ', $where );

		// Locked for the length of the transaction, so two workers cannot each
		// read the sketch, add their own hashes and write back over each other.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT uniques FROM `$name` WHERE $clause FOR UPDATE", $args ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( is_resource( $current ) ) {
			$current = stream_get_contents( $current );
		}

		$blob = $this->counter->record( $scope, $hashes, is_string( $current ) ? $current : null );

		if ( null === $blob ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE `$name` SET uniques = %s WHERE $clause", array_merge( [ $blob ], $args ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $updated ) {
			Log::warning( 'Could not store a unique-visitor sketch.' );
		}
	}
}
