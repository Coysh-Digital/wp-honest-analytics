<?php
/**
 * Folding hits into buckets.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use DateTimeZone;
use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a batch of hits into the handful of counters they represent.
 *
 * Everything is keyed by the *local* date and hour, because that is what the
 * rollup rows are keyed by and what a report means when it says "Tuesday".
 */
final class Aggregator {

	private Settings $settings;
	private DateTimeZone $timezone;

	/**
	 * Page buckets, keyed by site, date, hour and path.
	 *
	 * @var array<string,PageBucket>
	 */
	private array $buckets = [];

	public InteractionBuckets $interactions;

	/**
	 * @param Settings          $settings Settings.
	 * @param DateTimeZone|null $timezone Site timezone; resolved when omitted.
	 */
	public function __construct( Settings $settings, ?DateTimeZone $timezone = null ) {
		$this->settings     = $settings;
		$this->timezone     = $timezone ?? Timezone::site();
		$this->interactions = new InteractionBuckets();
	}

	/**
	 * Fold one hit in.
	 *
	 * @param Hit         $hit                 The hit.
	 * @param string|null $acquisitionReferrer The referrer the session arrived by.
	 */
	public function add( Hit $hit, ?string $acquisitionReferrer = null ): void {
		[ $date, $hour ] = Timezone::dateAndHour( $hit->timestamp, $this->timezone );

		// A crawler never touches a page, a session or a unique figure. It is
		// counted on its own screen and nowhere else.
		if ( Hit::KIND_CRAWLER === $hit->kind ) {
			$this->interactions->addCrawler( $hit, $date );

			return;
		}

		if ( ! $hit->isPageview() ) {
			$this->addInteraction( $hit, $date, $hour );

			return;
		}

		if ( null !== $hit->scrollBucket ) {
			$this->interactions->addScroll( $hit, $date );
		}

		if ( null !== $hit->searchTerm && $this->settings->trackSiteSearch ) {
			$this->interactions->addSearch( $hit, $date );
		}

		$key = PageBucket::key( $hit->siteId, $date, $hour, $hit->path );

		if ( ! isset( $this->buckets[ $key ] ) ) {
			$this->buckets[ $key ] = new PageBucket( $hit->siteId, $date, $hour, $hit->path, $hit->postId );
		}

		$this->buckets[ $key ]->add( $hit->visitorHash, $hit->countView, $hit->dwellMs );

		if ( $hit->countView ) {
			$this->interactions->addPageSource( $hit, $date, $acquisitionReferrer ?? $hit->referrer );
		}
	}

	/**
	 * Fold in something that happened on a page rather than being one.
	 *
	 * @param Hit    $hit  The hit.
	 * @param string $date Local date.
	 * @param int    $hour Local hour.
	 */
	private function addInteraction( Hit $hit, string $date, int $hour ): void {
		switch ( $hit->kind ) {
			case Hit::KIND_EVENT:
				$this->interactions->addEvent( $hit, $date, $hour );

				break;

			case Hit::KIND_OUTBOUND:
			case Hit::KIND_DOWNLOAD:
				$this->interactions->addOutbound( $hit, $date );

				break;
		}

		if ( null !== $hit->scrollBucket ) {
			$this->interactions->addScroll( $hit, $date );
		}
	}

	/**
	 * The page buckets accumulated.
	 *
	 * @return PageBucket[]
	 */
	public function buckets(): array {
		return array_values( $this->buckets );
	}

	/**
	 * How many buckets are held.
	 */
	public function bucketCount(): int {
		return count( $this->buckets );
	}

	/**
	 * The timezone in use.
	 */
	public function timezone(): DateTimeZone {
		return $this->timezone;
	}
}
