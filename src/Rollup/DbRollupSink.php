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
use HonestAnalytics\Devices\DeviceParser;
use HonestAnalytics\Dimensions\DimensionCapper;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Identity\IdentityService;
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
	private DeviceParser $devices;
	private ProRollupWriter $pro;
	private DateTimeZone $timezone;

	/**
	 * @param Settings               $settings Settings.
	 * @param DimensionCapper        $capper   Dimension capper.
	 * @param UniqueCounterInterface $counter  Unique counter.
	 * @param ChannelClassifier      $channels Channel classifier.
	 * @param DeviceParser           $devices  Device parser.
	 * @param ProRollupWriter        $pro      Pro rollup writer.
	 * @param DateTimeZone|null      $timezone Site timezone.
	 */
	public function __construct(
		Settings $settings,
		DimensionCapper $capper,
		UniqueCounterInterface $counter,
		ChannelClassifier $channels,
		DeviceParser $devices,
		ProRollupWriter $pro,
		?DateTimeZone $timezone = null
	) {
		$this->settings = $settings;
		$this->capper   = $capper;
		$this->counter  = $counter;
		$this->channels = $channels;
		$this->devices  = $devices;
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

		foreach ( $closedSessions as $session ) {
			$this->writeSession( $session );
		}

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

		$keys = [
			'siteId'    => $bucket->siteId,
			'date'      => $bucket->date,
			'hour'      => $bucket->hour,
			'pathDimId' => $pathDimId,
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
	 * Write everything a finished session contributes.
	 *
	 * @param Session $session Session.
	 */
	private function writeSession( Session $session ): void {
		[ $date, $hour ] = Timezone::dateAndHour( $session->startedAt, $this->timezone );

		$keys = [
			'siteId' => $session->siteId,
			'date'   => $date,
			'hour'   => $hour,
		];

		Upsert::counters(
			Tables::SESSIONS_ROLLUP,
			$keys,
			[
				'sessions'        => 1,
				'bounces'         => $session->isBounce() ? 1 : 0,
				'totalDurationMs' => $session->durationMs(),
				'totalPageviews'  => $session->pageviews,
			]
		);

		$this->recordUniques(
			Tables::SESSIONS_ROLLUP,
			$keys,
			new UniqueScope( UniqueScope::KIND_SESSION, $session->siteId, $date, $hour ),
			[ $session->visitorHash ]
		);

		$this->writeSource( $session, $date, $hour );
		$this->writeDevice( $session, $date );
		$this->writeEntryAndExit( $session, $date, $hour );

		$this->pro->writeSession( $session, $date, $this->capper, $this->timezone );
	}

	/**
	 * Write where a visit came from.
	 *
	 * @param Session $session Session.
	 * @param string  $date    Local date.
	 * @param int     $hour    Local hour.
	 */
	private function writeSource( Session $session, string $date, int $hour ): void {
		$hasCampaign = $this->settings->enableCampaigns && [] !== $session->campaigns;
		$channel     = $this->channels->classify( $session->referrer, $hasCampaign );
		$host        = $this->channels->host( $session->referrer );

		$refHostDimId = null === $host
			? 0
			: $this->capper->resolve( $session->siteId, $date, DimensionType::ReferrerHost, $host );

		Upsert::counters(
			Tables::SOURCES_ROLLUP,
			[
				'siteId'       => $session->siteId,
				'date'         => $date,
				'hour'         => $hour,
				'channel'      => $channel->value,
				'refHostDimId' => $refHostDimId,
			],
			[
				'sessions' => 1,
				'bounces'  => $session->isBounce() ? 1 : 0,
			]
		);
	}

	/**
	 * Write what a visit was browsing on.
	 *
	 * @param Session $session Session.
	 * @param string  $date    Local date.
	 */
	private function writeDevice( Session $session, string $date ): void {
		[ $browser, $major, $os, $type ] = $this->devices->parse( $session->userAgent );

		Upsert::counters(
			Tables::DEVICES_ROLLUP,
			[
				'siteId'       => $session->siteId,
				'date'         => $date,
				'browserDimId' => $this->capper->resolve( $session->siteId, $date, DimensionType::Browser, $browser ),
				'browserMajor' => $major,
				'osDimId'      => $this->capper->resolve( $session->siteId, $date, DimensionType::Os, $os ),
				'deviceType'   => $type->value,
			],
			[ 'sessions' => 1 ]
		);
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
