<?php
/**
 * Which reports to ask Google for.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Five small requests rather than one enormous one.
 *
 * The temptation with a reporting API is to ask for every dimension at once and
 * sort it out afterwards. It is the wrong shape here twice over: the response
 * is the product of every dimension's cardinality, so a year of pages by
 * country by device is millions of rows describing traffic nobody will ever
 * look at that way; and this plugin stores its rollups separately anyway, so
 * the combined rows would only be summed back apart again.
 *
 * So: one request per thing the destination actually keeps. Each is cheap,
 * each is independently resumable, and a quota limit costs one of them rather
 * than the lot.
 *
 * Deliberately not requested: anything user-scoped, anything with an id in it,
 * and hourly detail. The first two are none of our business, and the third
 * costs a request per day for something no screen here shows beyond a week.
 */
final class Reports {

	/** Page views, visitors and time, per page per day. */
	public const PAGES = 'pages';

	/** The day's totals, which no per-page sum can honestly reproduce. */
	public const TOTALS = 'totals';

	/** Where sessions came from. */
	public const SOURCES = 'sources';

	/** Sessions by country. */
	public const COUNTRIES = 'countries';

	/** Sessions by device category. */
	public const DEVICES = 'devices';

	/**
	 * The requests, in the order they are run.
	 *
	 * Totals first, so that a chunk which runs out of quota part way through
	 * has at least written the figures the dashboard leads with.
	 *
	 * @return array<int,array{key:string,dimensions:string[],metrics:string[],limit:int,orderBys:array<int,array<string,mixed>>}>
	 */
	public static function all(): array {
		return [
			[
				'key'        => self::TOTALS,
				'dimensions' => [ 'date' ],
				'metrics'    => [ 'sessions', 'activeUsers', 'screenPageViews', 'bounceRate', 'userEngagementDuration' ],
				'limit'      => 100000,
				'orderBys'   => [],
			],
			[
				'key'        => self::PAGES,
				'dimensions' => [ 'date', 'pagePath' ],
				'metrics'    => [ 'screenPageViews', 'activeUsers', 'sessions', 'userEngagementDuration' ],
				'limit'      => 100000,
				// Ordered, so that a report large enough to be truncated keeps
				// the pages somebody actually reads rather than an arbitrary
				// slice of the tail.
				'orderBys'   => [
					[
						'metric' => [ 'metricName' => 'screenPageViews' ],
						'desc'   => true,
					],
				],
			],
			[
				'key'        => self::SOURCES,
				'dimensions' => [ 'date', 'sessionSource', 'sessionMedium' ],
				'metrics'    => [ 'sessions', 'bounceRate' ],
				'limit'      => 100000,
				'orderBys'   => [
					[
						'metric' => [ 'metricName' => 'sessions' ],
						'desc'   => true,
					],
				],
			],
			[
				'key'        => self::COUNTRIES,
				'dimensions' => [ 'date', 'countryId' ],
				'metrics'    => [ 'sessions' ],
				'limit'      => 100000,
				'orderBys'   => [],
			],
			[
				'key'        => self::DEVICES,
				'dimensions' => [ 'date', 'deviceCategory' ],
				'metrics'    => [ 'sessions' ],
				'limit'      => 100000,
				'orderBys'   => [],
			],
		];
	}

	/**
	 * What each report contributes, for the preview screen.
	 *
	 * @return array<string,string>
	 */
	public static function describe(): array {
		return [
			self::TOTALS    => __( 'Daily page views, sessions and visitors', 'honest-analytics' ),
			self::PAGES     => __( 'Page views and time on page, per page', 'honest-analytics' ),
			self::SOURCES   => __( 'Where visits came from', 'honest-analytics' ),
			self::COUNTRIES => __( 'Countries', 'honest-analytics' ),
			self::DEVICES   => __( 'Devices', 'honest-analytics' ),
		];
	}
}
