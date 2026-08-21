<?php
/**
 * The diagnostics command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Scheduling\Cron;
use HonestAnalytics\Scheduling\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What this installation is actually doing.
 *
 * The first command to run when a site says the numbers look wrong. It answers,
 * in one screen: which drivers are in use, whether cron is running, how much is
 * waiting to be counted, when anything was last counted, and when the visitor
 * salt is next destroyed.
 */
final class InfoCommand {

	/**
	 * Shows the drivers, schedules, backlog and health of this installation.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics info
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function __invoke( array $args, array $assocArgs = [] ): void {
		unset( $args );

		$health  = new Health();
		$summary = $health->summary();
		$format  = $assocArgs['format'] ?? 'table';

		if ( 'table' !== $format ) {
			\WP_CLI\Utils\format_items( $format, [ $summary ], array_keys( $summary ) );

			return;
		}

		$rows = [
			[ __( 'Edition', 'honest-analytics' ), (string) $summary['edition'] ],
			[ __( 'Schema', 'honest-analytics' ), $summary['schemaCurrent'] ? __( 'current', 'honest-analytics' ) : __( 'needs upgrading', 'honest-analytics' ) ],
			[ __( 'Write driver', 'honest-analytics' ), (string) $summary['writeDriver'] ],
			[ __( 'Session store', 'honest-analytics' ), (string) $summary['sessionStore'] ],
			[ __( 'Key/value store', 'honest-analytics' ), $summary['objectCache'] ? __( 'object cache', 'honest-analytics' ) : __( 'database table', 'honest-analytics' ) ],
			[ __( 'Unique counting', 'honest-analytics' ), (string) $summary['uniques'] ],
			[ __( 'WP-Cron', 'honest-analytics' ), $summary['wpCron'] ? __( 'enabled', 'honest-analytics' ) : __( 'disabled (use a real cron job)', 'honest-analytics' ) ],
			[ __( 'Next drain', 'honest-analytics' ), self::when( $summary['nextDrain'] ) ],
			[ __( 'Next tidy-up', 'honest-analytics' ), self::when( $summary['nextGc'] ) ],
			[ __( 'Next salt check', 'honest-analytics' ), self::when( $summary['nextSalt'] ) ],
			[ __( 'Salt rotates', 'honest-analytics' ), self::describeSaltRotation() ],
			[ __( 'Backlog', 'honest-analytics' ), (string) $summary['backlog'] ],
			[ __( 'Last drain', 'honest-analytics' ), self::lastRun( $summary['lastDrain'] ) ],
			[ __( 'Last tidy-up', 'honest-analytics' ), self::lastRun( $summary['lastGc'] ) ],
		];

		$items = [];

		foreach ( $rows as [ $field, $value ] ) {
			$items[] = [
				'field' => $field,
				'value' => $value,
			];
		}

		\WP_CLI\Utils\format_items( 'table', $items, [ 'field', 'value' ] );

		foreach ( $health->advisories() as $advisory ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( \WP_CLI::colorize( '%B' . __( 'Note:', 'honest-analytics' ) . '%n ' ) . $advisory );
		}

		$problems = $health->problems();

		if ( [] === $problems ) {
			\WP_CLI::success( __( 'Nothing wrong.', 'honest-analytics' ) );

			return;
		}

		foreach ( $problems as $problem ) {
			\WP_CLI::warning( $problem );
		}

		\WP_CLI::halt( 1 );
	}

	/**
	 * When the salt is next destroyed.
	 *
	 * Delegates rather than duplicating, because the Settings screen says the
	 * same thing in the same words and the two must not drift apart. Asking
	 * SaltService instead would mint a salt - and "no salt exists yet" is a real
	 * state a diagnostic must be able to report rather than quietly resolve.
	 */
	private static function describeSaltRotation(): string {
		return Health::describeSaltRotation();
	}

	/**
	 * A scheduled time, in words.
	 *
	 * @param int|null $timestamp When.
	 */
	private static function when( ?int $timestamp ): string {
		if ( null === $timestamp ) {
			return __( 'not scheduled', 'honest-analytics' );
		}

		if ( $timestamp <= time() ) {
			return __( 'overdue', 'honest-analytics' );
		}

		return sprintf(
			/* translators: %s: human readable duration. */
			__( 'in %s', 'honest-analytics' ),
			human_time_diff( time(), $timestamp )
		);
	}

	/**
	 * A summary of a previous run.
	 *
	 * @param array<string,mixed>|null $run Stored run.
	 */
	private static function lastRun( ?array $run ): string {
		if ( null === $run || empty( $run['at'] ) ) {
			return __( 'never', 'honest-analytics' );
		}

		return sprintf(
			/* translators: %s: human readable duration. */
			__( '%s ago', 'honest-analytics' ),
			human_time_diff( (int) $run['at'], time() )
		);
	}
}
