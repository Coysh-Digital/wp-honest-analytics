<?php
/**
 * The seed command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Seed\Seeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fill a development site with traffic.
 *
 * Invented data, written through the real pipeline: spool, drain, rollup,
 * compaction. That matters, because the questions worth asking of a
 * development site are all volume questions - does the dashboard still render
 * at four hundred days, does compaction shrink anything, does the storage claim
 * hold - and none of them can be answered by clicking around.
 *
 * It refuses to run on anything that looks like production unless told twice.
 */
final class SeedCommand {

	/**
	 * Generates and records synthetic traffic.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : How many days back to generate.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--per-day=<number>]
	 * : Roughly how many pageviews per day.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--content]
	 * : Attribute the traffic to real posts and pages, creating a demo set first if the site has none.
	 *
	 * [--force]
	 * : Seed even though this does not look like a development site.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics seed --days=90 --per-day=400 --content
	 *
	 * ## NOTES
	 *
	 * --content creates demo posts, pages, categories, tags and authors when
	 * the site does not already have them, because a fresh WordPress has one
	 * post and one page and the Content screens have nothing to say about
	 * that. Everything it creates carries the _honest_analytics_demo meta key,
	 * so it is easy to find and to remove.
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function __invoke( array $args, array $assocArgs = [] ): void {
		unset( $args );

		$days = max( 1, (int) ( $assocArgs['days'] ?? 30 ) );

		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Seeding', 'honest-analytics' ), $days );

		$result = ( new Seeder() )->run(
			[
				'days'     => $days,
				'perDay'   => (int) ( $assocArgs['per-day'] ?? 200 ),
				'content'  => isset( $assocArgs['content'] ),
				'force'    => isset( $assocArgs['force'] ),
				'progress' => static function () use ( $progress ): void {
					$progress->tick();
				},
			]
		);

		$progress->finish();

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( (string) $result['error'] );

			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: hits, 2: visitors, 3: days, 4: seconds. */
				__( '%1$s hits from %2$s visitors across %3$s days, in %4$ss.', 'honest-analytics' ),
				number_format_i18n( (int) $result['hits'] ),
				number_format_i18n( (int) $result['visitors'] ),
				number_format_i18n( (int) $result['days'] ),
				number_format_i18n( (float) $result['seconds'], 1 )
			)
		);

		\WP_CLI::log( __( 'Run "wp honest-analytics gc" to compact it, which is the state a real site of this age would be in.', 'honest-analytics' ) );
	}
}
