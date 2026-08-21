<?php
/**
 * Building the rows an export contains.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Export;

use HonestAnalytics\Channels\Channel;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\ContentStatsService;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Stats\StatsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One table per screen that offers an export, in the same shape the screen
 * shows.
 *
 * The kinds here are exactly the values the report screens return from
 * `Screen::exportKind()`. That is not a coincidence to be tidied up later - it
 * is the whitelist. Anything arriving at the export handler that is not one of
 * these is refused, so the query string cannot ask for a report the reader
 * cannot already see on screen.
 *
 * Unique visitors are labelled as estimates wherever they appear, because they
 * are: a distinct count for a day, derived from a sketch, accurate to about a
 * percent and a half, and not addable across days. A column called `uniques`
 * in a spreadsheet would be summed by somebody within the week.
 */
final class Exporter {

	/**
	 * Every kind that can be exported.
	 *
	 * @var string[]
	 */
	public const KINDS = [ 'trend', 'pages', 'sources', 'devices', 'content' ];

	private StatsService $stats;
	private ContentStatsService $content;

	/**
	 * @param StatsService        $stats   Report queries.
	 * @param ContentStatsService $content Content report queries.
	 */
	public function __construct( StatsService $stats, ContentStatsService $content ) {
		$this->stats   = $stats;
		$this->content = $content;
	}

	/**
	 * Build one from the container.
	 */
	public static function make(): self {
		$plugin = Plugin::instance();

		return new self( $plugin->stats(), $plugin->contentStats() );
	}

	/**
	 * Whether a kind can be exported.
	 *
	 * @param string $kind Export kind.
	 */
	public static function supports( string $kind ): bool {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * The rows for one kind.
	 *
	 * @param string    $kind   Export kind.
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( string $kind, int $siteId, DateRange $range ): array {
		return match ( $kind ) {
			'trend'   => $this->trend( $siteId, $range ),
			'pages'   => $this->pages( $siteId, $range ),
			'sources' => $this->sources( $siteId, $range ),
			'devices' => $this->devices( $siteId, $range ),
			'content' => $this->content( $siteId, $range ),
			default   => [],
		};
	}

	/**
	 * The human name for a kind.
	 *
	 * @param string $kind Export kind.
	 */
	public function label( string $kind ): string {
		return match ( $kind ) {
			'trend'   => __( 'Overview', 'honest-analytics' ),
			'pages'   => __( 'Pages', 'honest-analytics' ),
			'sources' => __( 'Sources', 'honest-analytics' ),
			'devices' => __( 'Devices', 'honest-analytics' ),
			'content' => __( 'Content', 'honest-analytics' ),
			default   => $kind,
		};
	}

	/**
	 * What the reader needs alongside the numbers.
	 *
	 * @param string    $kind   Export kind.
	 * @param DateRange $range  Period.
	 *
	 * @return array<string,mixed>
	 */
	public function meta( string $kind, DateRange $range ): array {
		return [
			'plugin'         => 'Honest Analytics',
			'version'        => defined( 'HONEST_ANALYTICS_VERSION' ) ? HONEST_ANALYTICS_VERSION : '',
			'report'         => $this->label( $kind ),
			'kind'           => $kind,
			'site'           => get_bloginfo( 'name' ),
			'generatedAt'    => gmdate( 'c' ),
			'range'          => [
				'from'  => $range->from,
				'to'    => $range->to,
				'label' => $range->label,
			],
			'uniqueVisitors' => [
				'method'   => $this->stats->counter()->name(),
				'accuracy' => $this->stats->uniquesAccuracy(),
				'note'     => __( 'Unique visitors are an estimate of distinct visitors within a single day. Because the identifier behind them is destroyed every time the salt rotates, daily figures cannot be added together to give a figure for a longer period.', 'honest-analytics' ),
			],
		];
	}

	/**
	 * Views and visitors over time.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function trend( int $siteId, DateRange $range ): array {
		$trend = $this->stats->trend( $siteId, $range );

		$labels  = is_array( $trend['labels'] ?? null ) ? $trend['labels'] : [];
		$views   = is_array( $trend['views'] ?? null ) ? $trend['views'] : [];
		$uniques = is_array( $trend['uniques'] ?? null ) ? $trend['uniques'] : [];
		$hourly  = (bool) ( $trend['hourly'] ?? false );

		$rows = [];

		foreach ( $labels as $index => $label ) {
			$row = [ $hourly ? 'hour' : 'date' => (string) $label ];

			$row['views'] = (int) ( $views[ $index ] ?? 0 );

			// An hourly series has no per-hour unique counts to report, and a
			// column of zeroes would be read as "nobody was unique".
			if ( ! $hourly ) {
				$row['uniqueVisitorsEstimate'] = (int) ( $uniques[ $index ] ?? 0 );
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The pages table.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pages( int $siteId, DateRange $range ): array {
		$rows = [];

		foreach ( $this->stats->topPages( $siteId, $range, 1000 ) as $row ) {
			$rows[] = [
				'path'         => (string) $row['path'],
				'views'        => (int) $row['views'],
				'entrances'    => (int) $row['entrances'],
				'exits'        => (int) $row['exits'],
				'bounces'      => (int) $row['bounces'],
				'bounceRate'   => null === $row['bounceRate'] ? '' : round( (float) $row['bounceRate'], 1 ),
				'avgDwellSecs' => (int) round( (int) $row['avgDwellMs'] / 1000 ),
				'postId'       => (int) $row['postId'],
			];
		}

		return $rows;
	}

	/**
	 * The sources table.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sources( int $siteId, DateRange $range ): array {
		$rows = [];

		foreach ( $this->stats->sources( $siteId, $range, 1000 ) as $row ) {
			$rows[] = [
				'channel'    => Channel::fromStored( (int) $row['channel'] )->label(),
				// Direct traffic has no host, and an empty cell says that more
				// plainly than a placeholder somebody would try to look up.
				'host'       => (string) $row['host'],
				'sessions'   => (int) $row['sessions'],
				'bounces'    => (int) $row['bounces'],
				'bounceRate' => round( (float) $row['bounceRate'], 1 ),
			];
		}

		return $rows;
	}

	/**
	 * Browsers, operating systems and device types in one table.
	 *
	 * The screen shows these as three tabs. An export is a file, and three
	 * files for one button is worse than one file with a column saying which
	 * breakdown each row belongs to.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function devices( int $siteId, DateRange $range ): array {
		$groups = [
			'browser'    => __( 'Browser', 'honest-analytics' ),
			'os'         => __( 'Operating system', 'honest-analytics' ),
			'deviceType' => __( 'Device type', 'honest-analytics' ),
		];

		$rows = [];

		foreach ( $groups as $by => $label ) {
			foreach ( $this->stats->devices( $siteId, $range, $by ) as $row ) {
				$value = (string) ( $row['label'] ?? '' );

				$rows[] = [
					'breakdown' => $label,
					'label'     => '' === $value ? __( 'Unknown', 'honest-analytics' ) : $value,
					'sessions'  => (int) ( $row['sessions'] ?? 0 ),
				];
			}
		}

		return $rows;
	}

	/**
	 * Post types, taxonomy terms and authors in one table.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function content( int $siteId, DateRange $range ): array {
		$groups = [
			[ __( 'Post type', 'honest-analytics' ), $this->content->byPostType( $siteId, $range, 500 ) ],
			[ __( 'Term', 'honest-analytics' ), $this->content->byTaxonomy( $siteId, $range, 500 ) ],
			[ __( 'Author', 'honest-analytics' ), $this->content->byAuthor( $siteId, $range, 500 ) ],
		];

		$rows = [];

		foreach ( $groups as [ $label, $group ] ) {
			foreach ( $group as $row ) {
				$rows[] = [
					'breakdown'    => $label,
					'label'        => (string) ( $row['label'] ?? '' ),
					'key'          => (string) ( $row['key'] ?? '' ),
					'views'        => (int) ( $row['views'] ?? 0 ),
					'posts'        => (int) ( $row['posts'] ?? 0 ),
					'viewsPerPost' => (int) ( $row['perPost'] ?? 0 ),
					'avgDwellSecs' => (int) round( (int) ( $row['avgDwellMs'] ?? 0 ) / 1000 ),
				];
			}
		}

		return $rows;
	}
}
