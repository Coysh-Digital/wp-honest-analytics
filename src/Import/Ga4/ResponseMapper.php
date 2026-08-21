<?php
/**
 * Turning GA4 rows into this plugin's own shapes.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

use HonestAnalytics\Capture\PathNormalizer;
use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Devices\DeviceType;
use HonestAnalytics\Import\DayBucket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One chunk of GA4 responses, assembled into whole days.
 *
 * Days are accumulated here rather than written as each report arrives,
 * because the sink writes a day whole - it clears everything that source has
 * for a date and inserts the replacement. Writing after each of the five
 * reports would mean each one wiping the last. So the chunk is assembled, then
 * handed over complete.
 *
 * That also makes a chunk the natural retry unit: a rate limit half way through
 * costs the chunk, not the day, and the chunk is written only once it is whole.
 */
final class ResponseMapper {

	/**
	 * GA4 source names that are a search engine or network rather than a host.
	 *
	 * Google derives these tokens from the very domains they are listed against,
	 * so turning `google` back into `google.com` is decoding a documented
	 * convention rather than inventing a fact. It matters because native rows
	 * store `google.com`: without this, a site's referrers would split into two
	 * sets on the migration date and neither would look right.
	 *
	 * Anything not here and not already host-shaped is imported with no host at
	 * all. A channel with no referrer is honest; a hostname somebody made up is
	 * not.
	 *
	 * @var array<string,string>
	 */
	private const KNOWN_SOURCES = [
		'google'     => 'google.com',
		'bing'       => 'bing.com',
		'yahoo'      => 'yahoo.com',
		'duckduckgo' => 'duckduckgo.com',
		'baidu'      => 'baidu.com',
		'yandex'     => 'yandex.com',
		'ecosia'     => 'ecosia.org',
		'ask'        => 'ask.com',
		'aol'        => 'aol.com',
		'facebook'   => 'facebook.com',
		'instagram'  => 'instagram.com',
		'linkedin'   => 'linkedin.com',
		'twitter'    => 'twitter.com',
		'x'          => 'x.com',
		'pinterest'  => 'pinterest.com',
		'reddit'     => 'reddit.com',
		'youtube'    => 'youtube.com',
		'tiktok'     => 'tiktok.com',
		'quora'      => 'quora.com',
		'medium'     => 'medium.com',
		'github'     => 'github.com',
		'threads'    => 'threads.net',
	];

	/**
	 * GA4 mediums that mean a tagged campaign.
	 *
	 * @var string[]
	 */
	private const CAMPAIGN_MEDIUMS = [
		'cpc',
		'ppc',
		'paid',
		'paidsearch',
		'paid_search',
		'paid-search',
		'display',
		'banner',
		'cpm',
		'cpv',
		'cpa',
		'affiliate',
		'email',
		'e-mail',
		'newsletter',
		'sms',
		'push',
		'retargeting',
		'paid_social',
		'paidsocial',
		'paid-social',
	];

	/**
	 * GA4 mediums that mean social.
	 *
	 * @var string[]
	 */
	private const SOCIAL_MEDIUMS = [ 'social', 'social-network', 'social_network', 'social media', 'social-media', 'sm', 'organic_social', 'organic-social' ];

	/**
	 * Days built so far, keyed by Y-m-d.
	 *
	 * @var array<string,DayBucket>
	 */
	private array $buckets = [];

	private int $rowsRead = 0;

	private int $rowsSkipped = 0;

	/**
	 * @param PathNormalizer $paths The same normalisation native page views get.
	 */
	public function __construct( private PathNormalizer $paths ) {
	}

	/**
	 * The days assembled so far.
	 *
	 * @return array<string,DayBucket>
	 */
	public function buckets(): array {
		ksort( $this->buckets );

		return $this->buckets;
	}

	public function rowsRead(): int {
		return $this->rowsRead;
	}

	public function rowsSkipped(): int {
		return $this->rowsSkipped;
	}

	/**
	 * Apply one report's rows.
	 *
	 * @param string                                                 $report One of the Reports constants.
	 * @param array<int,array{dimensions:string[],metrics:string[]}> $rows   Normalised rows from the client.
	 */
	public function apply( string $report, array $rows ): void {
		foreach ( $rows as $row ) {
			++$this->rowsRead;

			$date = self::date( $row['dimensions'][0] ?? '' );

			if ( '' === $date ) {
				++$this->rowsSkipped;

				continue;
			}

			$bucket = $this->bucket( $date );

			switch ( $report ) {
				case Reports::TOTALS:
					$this->applyTotals( $bucket, $row );
					break;

				case Reports::PAGES:
					$this->applyPage( $bucket, $row );
					break;

				case Reports::SOURCES:
					$this->applySource( $bucket, $row );
					break;

				case Reports::COUNTRIES:
					$bucket->addCountry( (string) ( $row['dimensions'][1] ?? '' ), self::int( $row['metrics'][0] ?? '0' ) );
					break;

				case Reports::DEVICES:
					$this->applyDevice( $bucket, $row );
					break;

				default:
					++$this->rowsSkipped;
			}
		}
	}

	/**
	 * The day's headline figures.
	 *
	 * @param DayBucket                                   $bucket The day.
	 * @param array{dimensions:string[],metrics:string[]} $row    sessions, activeUsers, screenPageViews, bounceRate, userEngagementDuration.
	 */
	private function applyTotals( DayBucket $bucket, array $row ): void {
		$sessions = self::int( $row['metrics'][0] ?? '0' );
		$rate     = self::float( $row['metrics'][3] ?? '0' );

		$bucket->addTotals(
			$sessions,
			self::int( $row['metrics'][1] ?? '0' ),
			self::int( $row['metrics'][2] ?? '0' ),
			(int) round( $sessions * max( 0.0, min( 1.0, $rate ) ) ),
			// GA4 reports engagement time in seconds, and engagement time is
			// not session duration - it stops when the tab loses focus. Carried
			// across because it is the closest thing GA4 has, and marked
			// approximate where it is described.
			(int) round( self::float( $row['metrics'][4] ?? '0' ) * 1000 )
		);
	}

	/**
	 * One page on one day.
	 *
	 * @param DayBucket                                   $bucket The day.
	 * @param array{dimensions:string[],metrics:string[]} $row    pagePath then screenPageViews, activeUsers, sessions, engagement.
	 */
	private function applyPage( DayBucket $bucket, array $row ): void {
		$raw = (string) ( $row['dimensions'][1] ?? '' );

		if ( '' === $raw || '(not set)' === $raw ) {
			++$this->rowsSkipped;

			return;
		}

		[ $path, $query ] = PathNormalizer::split( $raw );

		$normalised = $this->paths->normalize( $path, $query );

		if ( '' === $normalised ) {
			++$this->rowsSkipped;

			return;
		}

		$bucket->addPage(
			$normalised,
			self::int( $row['metrics'][0] ?? '0' ),
			self::int( $row['metrics'][1] ?? '0' ),
			0,
			0,
			0,
			(int) round( self::float( $row['metrics'][3] ?? '0' ) * 1000 )
		);
	}

	/**
	 * Where a day's sessions came from.
	 *
	 * @param DayBucket                                   $bucket The day.
	 * @param array{dimensions:string[],metrics:string[]} $row    source, medium then sessions, bounceRate.
	 */
	private function applySource( DayBucket $bucket, array $row ): void {
		$source   = strtolower( trim( (string) ( $row['dimensions'][1] ?? '' ) ) );
		$medium   = strtolower( trim( (string) ( $row['dimensions'][2] ?? '' ) ) );
		$sessions = self::int( $row['metrics'][0] ?? '0' );
		$rate     = self::float( $row['metrics'][1] ?? '0' );

		$bucket->addSource(
			self::channel( $source, $medium ),
			self::host( $source ),
			$sessions,
			(int) round( $sessions * max( 0.0, min( 1.0, $rate ) ) )
		);
	}

	/**
	 * Sessions by device.
	 *
	 * @param DayBucket                                   $bucket The day.
	 * @param array{dimensions:string[],metrics:string[]} $row    deviceCategory then sessions.
	 */
	private function applyDevice( DayBucket $bucket, array $row ): void {
		// Browser and operating system are left empty on purpose. GA4 reports
		// them, but under its own names and its own grouping, and importing
		// "Chrome" beside a natively parsed "Chrome 126" would produce two rows
		// that look like two browsers. Device category is coarse enough to
		// survive the translation.
		$bucket->addDevice( '', 0, '', self::deviceType( (string) ( $row['dimensions'][1] ?? '' ) ), self::int( $row['metrics'][0] ?? '0' ) );
	}

	/**
	 * The day this bucket belongs to.
	 *
	 * @param string $date Y-m-d.
	 */
	private function bucket( string $date ): DayBucket {
		if ( ! isset( $this->buckets[ $date ] ) ) {
			$this->buckets[ $date ] = new DayBucket( $date );
		}

		return $this->buckets[ $date ];
	}

	/**
	 * GA4's YYYYMMDD, as a calendar date.
	 *
	 * @param string $value The dimension value.
	 */
	public static function date( string $value ): string {
		$value = trim( $value );

		if ( 1 !== preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $matches ) ) {
			return '';
		}

		if ( ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
			return '';
		}

		return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
	}

	/**
	 * Which channel a GA4 source and medium describe.
	 *
	 * Medium first, because in GA4 the medium is the authoritative signal and
	 * the source is a label attached to it.
	 *
	 * @param string $source Lower-cased sessionSource.
	 * @param string $medium Lower-cased sessionMedium.
	 */
	public static function channel( string $source, string $medium ): Channel {
		if ( in_array( $medium, self::CAMPAIGN_MEDIUMS, true ) ) {
			return Channel::Campaign;
		}

		if ( in_array( $medium, self::SOCIAL_MEDIUMS, true ) ) {
			return Channel::Social;
		}

		if ( 'organic' === $medium ) {
			return Channel::Search;
		}

		if ( 'referral' === $medium ) {
			return Channel::Referral;
		}

		if ( '(none)' === $medium || '' === $medium || '(not set)' === $medium ) {
			return '(direct)' === $source || '' === $source || '(not set)' === $source
				? Channel::Direct
				: Channel::Referral;
		}

		return Channel::Referral;
	}

	/**
	 * The referring host a GA4 source names, if it names one at all.
	 *
	 * @param string $source Lower-cased sessionSource.
	 */
	public static function host( string $source ): string {
		$source = trim( $source );

		if ( '' === $source || '(direct)' === $source || '(not set)' === $source || 'direct' === $source ) {
			return '';
		}

		if ( isset( self::KNOWN_SOURCES[ $source ] ) ) {
			return self::KNOWN_SOURCES[ $source ];
		}

		// Already a hostname: `t.co`, `l.facebook.com`, `news.ycombinator.com`.
		if ( 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $source ) ) {
			return $source;
		}

		return '';
	}

	/**
	 * GA4's device category, as one of this plugin's four.
	 *
	 * @param string $category The dimension value.
	 */
	public static function deviceType( string $category ): DeviceType {
		switch ( strtolower( trim( $category ) ) ) {
			case 'desktop':
				return DeviceType::Desktop;

			case 'mobile':
				return DeviceType::Mobile;

			case 'tablet':
				return DeviceType::Tablet;
		}

		// `smart tv`, `console`, `(not set)` and anything Google adds later.
		// Unknown is the honest answer; inventing a fifth category here would
		// put a value in the reports that native tracking can never produce.
		return DeviceType::Unknown;
	}

	/**
	 * A metric value as an integer.
	 *
	 * @param string $value Raw metric value.
	 */
	private static function int( string $value ): int {
		return (int) round( self::float( $value ) );
	}

	/**
	 * A metric value as a float.
	 *
	 * @param string $value Raw metric value.
	 */
	private static function float( string $value ): float {
		$value = trim( $value );

		return is_numeric( $value ) ? (float) $value : 0.0;
	}
}
