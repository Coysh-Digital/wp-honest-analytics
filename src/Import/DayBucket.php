<?php
/**
 * One day of imported analytics, in this plugin's own terms.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Devices\DeviceType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shape every importer produces and the sink consumes.
 *
 * Importers do not write to the database. They read whatever their source
 * keeps - a table of daily page counts, a table of sessions, a GA4 report -
 * and fill one of these per day. Everything after that is shared, which is what
 * stops three importers each inventing their own idea of what a page view is
 * and each getting the provenance, the deduplication and the timezone slightly
 * differently wrong.
 *
 * A day is the unit because a day is what every source agrees on. Hourly
 * detail is not imported: WP Statistics does not keep it, Independent Analytics
 * keeps timestamps this plugin would have to re-bucket into a timezone it
 * cannot verify, and GA4's hourly dimension costs a request per day for
 * something no report here shows beyond a week. Imported rows are daily rows -
 * `hour = -1`, the same shape the compactor leaves behind.
 */
final class DayBucket {

	/** The daily row marker, matching what Compactor writes. */
	public const DAILY_HOUR = -1;

	/**
	 * Per-path figures, keyed by normalised path.
	 *
	 * @var array<string,array{views:int,visitors:int,entrances:int,exits:int,bounces:int,dwellMs:int,postId:int|null}>
	 */
	private array $pages = [];

	/**
	 * Per-channel-and-host figures, keyed by "channel|host".
	 *
	 * @var array<string,array{channel:int,host:string,sessions:int,bounces:int}>
	 */
	private array $sources = [];

	/**
	 * Per-device figures, keyed by "browser|major|os|type".
	 *
	 * @var array<string,array{browser:string,browserMajor:int,os:string,deviceType:int,sessions:int}>
	 */
	private array $devices = [];

	/**
	 * Sessions by two-letter country code.
	 *
	 * @var array<string,int>
	 */
	private array $countries = [];

	private int $sessions   = 0;
	private int $bounces    = 0;
	private int $pageviews  = 0;
	private int $visitors   = 0;
	private int $durationMs = 0;

	/**
	 * @param string $date A calendar date, Y-m-d, already in the site's timezone.
	 */
	public function __construct( public readonly string $date ) {
	}

	/**
	 * Add page-level figures.
	 *
	 * Called once per path per day. Repeated calls for the same path add
	 * together, because a source may report the same page under several rows -
	 * `/about` and `/about/?utm_source=x` normalise to one path here.
	 *
	 * @param string   $path      Already normalised by PathNormalizer.
	 * @param int      $views     Page views.
	 * @param int      $visitors  Distinct visitors, as the source counted them.
	 * @param int      $entrances Sessions that started here.
	 * @param int      $exits     Sessions that ended here.
	 * @param int      $bounces   Single-page sessions that started here.
	 * @param int      $dwellMs   Total time on page, in milliseconds.
	 * @param int|null $postId    The WordPress post, when the path resolves to one.
	 */
	public function addPage(
		string $path,
		int $views,
		int $visitors = 0,
		int $entrances = 0,
		int $exits = 0,
		int $bounces = 0,
		int $dwellMs = 0,
		?int $postId = null
	): void {
		if ( '' === $path ) {
			return;
		}

		if ( ! isset( $this->pages[ $path ] ) ) {
			$this->pages[ $path ] = [
				'views'     => 0,
				'visitors'  => 0,
				'entrances' => 0,
				'exits'     => 0,
				'bounces'   => 0,
				'dwellMs'   => 0,
				'postId'    => null,
			];
		}

		$this->pages[ $path ]['views']     += max( 0, $views );
		$this->pages[ $path ]['visitors']  += max( 0, $visitors );
		$this->pages[ $path ]['entrances'] += max( 0, $entrances );
		$this->pages[ $path ]['exits']     += max( 0, $exits );
		$this->pages[ $path ]['bounces']   += max( 0, $bounces );
		$this->pages[ $path ]['dwellMs']   += max( 0, $dwellMs );

		if ( null !== $postId && $postId > 0 ) {
			$this->pages[ $path ]['postId'] = $postId;
		}
	}

	/**
	 * Add the day's site-wide totals.
	 *
	 * @param int $sessions   Sessions, or the source's nearest equivalent.
	 * @param int $visitors   Distinct visitors on this day.
	 * @param int $pageviews  Page views, if the source reports them separately.
	 * @param int $bounces    Single-page sessions.
	 * @param int $durationMs Total session duration, in milliseconds.
	 */
	public function addTotals( int $sessions, int $visitors = 0, int $pageviews = 0, int $bounces = 0, int $durationMs = 0 ): void {
		$this->sessions   += max( 0, $sessions );
		$this->visitors   += max( 0, $visitors );
		$this->pageviews  += max( 0, $pageviews );
		$this->bounces    += max( 0, $bounces );
		$this->durationMs += max( 0, $durationMs );
	}

	/**
	 * Add sessions attributed to a channel and referring host.
	 *
	 * @param Channel $channel  Classified channel.
	 * @param string  $host     Referring host, or '' for direct.
	 * @param int     $sessions Sessions.
	 * @param int     $bounces  Single-page sessions.
	 */
	public function addSource( Channel $channel, string $host, int $sessions, int $bounces = 0 ): void {
		$key = $channel->value . '|' . $host;

		if ( ! isset( $this->sources[ $key ] ) ) {
			$this->sources[ $key ] = [
				'channel'  => $channel->value,
				'host'     => $host,
				'sessions' => 0,
				'bounces'  => 0,
			];
		}

		$this->sources[ $key ]['sessions'] += max( 0, $sessions );
		$this->sources[ $key ]['bounces']  += max( 0, $bounces );
	}

	/**
	 * Add sessions for a browser, operating system and device type.
	 *
	 * @param string     $browser      Browser family, or '' if unknown.
	 * @param int        $browserMajor Major version, or 0.
	 * @param string     $os           Operating system family, or ''.
	 * @param DeviceType $deviceType   Device category.
	 * @param int        $sessions     Sessions.
	 */
	public function addDevice( string $browser, int $browserMajor, string $os, DeviceType $deviceType, int $sessions ): void {
		$key = $browser . '|' . $browserMajor . '|' . $os . '|' . $deviceType->value;

		if ( ! isset( $this->devices[ $key ] ) ) {
			$this->devices[ $key ] = [
				'browser'      => $browser,
				'browserMajor' => $browserMajor,
				'os'           => $os,
				'deviceType'   => $deviceType->value,
				'sessions'     => 0,
			];
		}

		$this->devices[ $key ]['sessions'] += max( 0, $sessions );
	}

	/**
	 * Add sessions for a country.
	 *
	 * @param string $countryCode Two-letter ISO code; anything else is ignored.
	 * @param int    $sessions    Sessions.
	 */
	public function addCountry( string $countryCode, int $sessions ): void {
		$code = strtoupper( trim( $countryCode ) );

		if ( 2 !== strlen( $code ) || 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return;
		}

		$this->countries[ $code ] = ( $this->countries[ $code ] ?? 0 ) + max( 0, $sessions );
	}

	/**
	 * Whether there is anything here worth writing.
	 */
	public function isEmpty(): bool {
		return [] === $this->pages
			&& [] === $this->sources
			&& [] === $this->devices
			&& [] === $this->countries
			&& 0 === $this->sessions
			&& 0 === $this->visitors
			&& 0 === $this->pageviews;
	}

	/**
	 * How many rows this will become, near enough, for the progress figure.
	 */
	public function rowCount(): int {
		return count( $this->pages ) + count( $this->sources ) + count( $this->devices ) + count( $this->countries )
			+ ( $this->sessions > 0 || $this->visitors > 0 ? 1 : 0 );
	}

	/** @return array<string,array{views:int,visitors:int,entrances:int,exits:int,bounces:int,dwellMs:int,postId:int|null}> */
	public function pages(): array {
		return $this->pages;
	}

	/** @return array<string,array{channel:int,host:string,sessions:int,bounces:int}> */
	public function sources(): array {
		return $this->sources;
	}

	/** @return array<string,array{browser:string,browserMajor:int,os:string,deviceType:int,sessions:int}> */
	public function devices(): array {
		return $this->devices;
	}

	/** @return array<string,int> */
	public function countries(): array {
		return $this->countries;
	}

	public function sessions(): int {
		return $this->sessions;
	}

	public function bounces(): int {
		return $this->bounces;
	}

	/**
	 * The day's page views.
	 *
	 * Falls back to the sum of the per-page figures, because a source that
	 * reports pages but not a daily total still knows what the total is.
	 */
	public function pageviews(): int {
		if ( $this->pageviews > 0 ) {
			return $this->pageviews;
		}

		return (int) array_sum( array_column( $this->pages, 'views' ) );
	}

	public function visitors(): int {
		return $this->visitors;
	}

	public function durationMs(): int {
		return $this->durationMs;
	}
}
