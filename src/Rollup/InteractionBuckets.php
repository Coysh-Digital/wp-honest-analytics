<?php
/**
 * Everything that is not a pageview.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use HonestAnalytics\Capture\Hit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events, scroll depth, outbound clicks, downloads, searches and crawlers.
 *
 * Each map is keyed by whatever makes a row unique in its table, so the drain
 * hands the sink a handful of upserts rather than one per hit.
 *
 * Note the differing grains, which are not an oversight. Events keep an hour
 * because "when did that spike" is a question people ask of them. Scroll,
 * outbound, search and crawlers are daily, because an hour column would
 * multiply those tables by twenty-four to answer a question nobody asks.
 */
final class InteractionBuckets {

	/** @var array<string,array{siteId:int,date:string,hour:int,name:string,path:string,count:int,sessions:array<string,true>,value:float}> */
	public array $events = [];

	/** @var array<string,array{siteId:int,date:string,path:string,bucket:int,count:int}> */
	public array $scroll = [];

	/** @var array<string,array{siteId:int,date:string,host:string,url:string,path:string,count:int}> */
	public array $outbound = [];

	/** @var array<string,array{siteId:int,date:string,term:string,count:int,zeroResults:int}> */
	public array $searches = [];

	/** @var array<string,array{siteId:int,date:string,name:string,requests:int}> */
	public array $crawlers = [];

	/** @var array<string,array{siteId:int,date:string,path:string,referrer:string,views:int}> */
	public array $pageSources = [];

	/**
	 * Whether anything at all was recorded.
	 */
	public function isEmpty(): bool {
		return [] === $this->events
			&& [] === $this->scroll
			&& [] === $this->outbound
			&& [] === $this->searches
			&& [] === $this->crawlers
			&& [] === $this->pageSources;
	}

	/**
	 * Record a custom event.
	 *
	 * @param Hit    $hit  The hit.
	 * @param string $date Local date.
	 * @param int    $hour Local hour.
	 */
	public function addEvent( Hit $hit, string $date, int $hour ): void {
		$name = trim( (string) $hit->eventName );

		if ( '' === $name ) {
			return;
		}

		$key = implode( '|', [ $hit->siteId, $date, $hour, $name, $hit->path ] );

		if ( ! isset( $this->events[ $key ] ) ) {
			$this->events[ $key ] = [
				'siteId'   => $hit->siteId,
				'date'     => $date,
				'hour'     => $hour,
				'name'     => $name,
				'path'     => $hit->path,
				'count'    => 0,
				'sessions' => [],
				'value'    => 0.0,
			];
		}

		++$this->events[ $key ]['count'];
		$this->events[ $key ]['value'] += $hit->eventValue ?? 0.0;

		if ( '' !== $hit->sessionKey ) {
			$this->events[ $key ]['sessions'][ $hit->sessionKey ] = true;
		}
	}

	/**
	 * Record how far down a page somebody read.
	 *
	 * @param Hit    $hit  The hit.
	 * @param string $date Local date.
	 */
	public function addScroll( Hit $hit, string $date ): void {
		$bucket = $hit->scrollBucket;

		if ( ! in_array( $bucket, [ 25, 50, 75, 100 ], true ) ) {
			return;
		}

		$key = implode( '|', [ $hit->siteId, $date, $hit->path, $bucket ] );

		if ( ! isset( $this->scroll[ $key ] ) ) {
			$this->scroll[ $key ] = [
				'siteId' => $hit->siteId,
				'date'   => $date,
				'path'   => $hit->path,
				'bucket' => $bucket,
				'count'  => 0,
			];
		}

		++$this->scroll[ $key ]['count'];
	}

	/**
	 * Record a click that left the site, or a file that was downloaded.
	 *
	 * @param Hit    $hit  The hit.
	 * @param string $date Local date.
	 */
	public function addOutbound( Hit $hit, string $date ): void {
		$target = (string) $hit->target;

		if ( '' === trim( $target ) ) {
			return;
		}

		$host = wp_parse_url( $target, PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? strtolower( $host ) : '';

		if ( '' === $host ) {
			return;
		}

		$key = implode( '|', [ $hit->siteId, $date, $host, $target, $hit->path ] );

		if ( ! isset( $this->outbound[ $key ] ) ) {
			$this->outbound[ $key ] = [
				'siteId' => $hit->siteId,
				'date'   => $date,
				'host'   => $host,
				'url'    => $target,
				'path'   => $hit->path,
				'count'  => 0,
			];
		}

		++$this->outbound[ $key ]['count'];
	}

	/**
	 * Record a site search.
	 *
	 * @param Hit    $hit  The hit.
	 * @param string $date Local date.
	 */
	public function addSearch( Hit $hit, string $date ): void {
		$term = trim( (string) $hit->searchTerm );

		if ( '' === $term ) {
			return;
		}

		$key = implode( '|', [ $hit->siteId, $date, $term ] );

		if ( ! isset( $this->searches[ $key ] ) ) {
			$this->searches[ $key ] = [
				'siteId'      => $hit->siteId,
				'date'        => $date,
				'term'        => $term,
				'count'       => 0,
				'zeroResults' => 0,
			];
		}

		++$this->searches[ $key ]['count'];
	}

	/**
	 * Record a crawler request.
	 *
	 * @param Hit    $hit  The hit.
	 * @param string $date Local date.
	 */
	public function addCrawler( Hit $hit, string $date ): void {
		$name = trim( (string) $hit->eventName );

		if ( '' === $name ) {
			return;
		}

		$key = implode( '|', [ $hit->siteId, $date, $name ] );

		if ( ! isset( $this->crawlers[ $key ] ) ) {
			$this->crawlers[ $key ] = [
				'siteId'   => $hit->siteId,
				'date'     => $date,
				'name'     => $name,
				'requests' => 0,
			];
		}

		++$this->crawlers[ $key ]['requests'];
	}

	/**
	 * Record how a visit reached a particular page.
	 *
	 * The referrer stored is the one the *session* arrived by, not the previous
	 * page on this site: classifying that would report every interior page as
	 * self-referred, which is true and useless.
	 *
	 * @param Hit    $hit      The hit.
	 * @param string $date     Local date.
	 * @param string $referrer Acquisition referrer.
	 */
	public function addPageSource( Hit $hit, string $date, string $referrer ): void {
		$key = implode( '|', [ $hit->siteId, $date, $hit->path, $referrer ] );

		if ( ! isset( $this->pageSources[ $key ] ) ) {
			$this->pageSources[ $key ] = [
				'siteId'   => $hit->siteId,
				'date'     => $date,
				'path'     => $hit->path,
				'referrer' => $referrer,
				'views'    => 0,
			];
		}

		++$this->pageSources[ $key ]['views'];
	}
}
