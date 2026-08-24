<?php
/**
 * The drain command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Plugin;
use HonestAnalytics\Write\DrainResult;
use HonestAnalytics\Write\SpoolStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn spooled hits into totals.
 *
 * The command a real cron job runs. It exits non-zero when anything failed, so
 * a monitoring system notices a drain that is quietly dropping batches rather
 * than finding out weeks later from a graph that flattened.
 */
final class DrainCommand {

	/**
	 * Drains the spool into the report tables.
	 *
	 * ## OPTIONS
	 *
	 * [--retry]
	 * : Put batches that were set aside after repeated failures back in the queue first.
	 *
	 * [--watch]
	 * : Keep draining every few seconds until interrupted. For development.
	 *
	 * [--quiet]
	 * : Say nothing unless something went wrong. For crontab entries.
	 *
	 * [--network]
	 * : Drain every site on the network, not just this one.
	 *
	 * ## EXAMPLES
	 *
	 *     # What a crontab entry should call.
	 *     $ wp honest-analytics drain --quiet
	 *
	 *     # One line for a whole network.
	 *     $ wp honest-analytics drain --network --quiet
	 *
	 *     # Follow the pipeline while testing.
	 *     $ wp honest-analytics drain --watch
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assocArgs  Options.
	 */
	public function __invoke( array $args, array $assocArgs = [] ): void {
		unset( $args );

		if ( isset( $assocArgs['network'] ) ) {
			$this->everySite( $assocArgs );

			return;
		}

		$quiet   = isset( $assocArgs['quiet'] );
		$drainer = Plugin::instance()->drainer();

		if ( isset( $assocArgs['retry'] ) ) {
			$requeued = $drainer->retryFailed();

			\WP_CLI::log(
				sprintf(
					/* translators: %d: number of batches. */
					_n( '%d quarantined batch requeued.', '%d quarantined batches requeued.', $requeued, 'honest-analytics' ),
					$requeued
				)
			);
		}

		if ( isset( $assocArgs['watch'] ) ) {
			$this->watch( $drainer );

			return;
		}

		$result = $drainer->run();

		if ( ! $quiet ) {
			$this->report( $result );
		}

		if ( $result->hasFailures() ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: failed batches, 2: quarantined batches. */
					__( '%1$d batch(es) failed and %2$d are quarantined. Run with --retry once the cause is fixed.', 'honest-analytics' ),
					$result->failedBatches,
					$result->quarantinedBatches
				)
			);
		}
	}

	/**
	 * Drain each site on the network in turn.
	 *
	 * Sequential rather than parallel on purpose: the point of a bounded queue
	 * is that draining it is cheap, and fifty sites draining at once on shared
	 * hosting is how a maintenance job becomes an outage. Each site keeps its
	 * own tables, so there is nothing to reconcile between them.
	 *
	 * @param array<string,string> $assocArgs Options, minus --network.
	 */
	private function everySite( array $assocArgs ): void {
		if ( ! is_multisite() ) {
			\WP_CLI::error( __( '--network needs a multisite install.', 'honest-analytics' ) );
		}

		unset( $assocArgs['network'] );

		$quiet = isset( $assocArgs['quiet'] );
		$sites = get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		);

		foreach ( $sites as $siteId ) {
			switch_to_blog( (int) $siteId );

			// `finally`, because a drain that throws leaves every query after
			// it pointed at this site's tables - so the run would carry on and
			// write the remaining sites' hits into whichever one failed.
			try {
				// Services are built from this site's settings, so the
				// container has to be rebuilt as the prefix changes underneath
				// it.
				Plugin::reset();

				$result = Plugin::instance()->drainer()->run();

				if ( ! $quiet || $result->hasFailures() ) {
					\WP_CLI::log( sprintf( '%s:', home_url() ) );
					$this->report( $result );
				}
			} finally {
				restore_current_blog();
			}
		}

		Plugin::reset();

		if ( ! $quiet ) {
			\WP_CLI::success(
				sprintf(
					/* translators: %d: number of sites. */
					_n( 'Drained %d site.', 'Drained %d sites.', count( $sites ), 'honest-analytics' ),
					count( $sites )
				)
			);
		}
	}

	/**
	 * Drain repeatedly until interrupted.
	 *
	 * @param \HonestAnalytics\Write\Drainer $drainer The drainer.
	 */
	private function watch( $drainer ): void {
		$status = new SpoolStatus( Plugin::instance()->settings() );

		\WP_CLI::log( __( 'Draining every five seconds. Press Ctrl-C to stop.', 'honest-analytics' ) );

		while ( true ) {
			$result = $drainer->run();

			if ( $result->hits > 0 || $result->closedSessions > 0 || $result->hasFailures() ) {
				\WP_CLI::log(
					sprintf(
						'[%s] %d hits, %d buckets, %d visits closed, %s',
						gmdate( 'H:i:s' ),
						$result->hits,
						$result->buckets,
						$result->closedSessions,
						$status->describe()
					)
				);
			}

			sleep( 5 );
		}
	}

	/**
	 * Print what the drain did.
	 *
	 * @param DrainResult $result Result.
	 */
	private function report( DrainResult $result ): void {
		$rows = [];

		foreach ( $result->toArray() as $field => $value ) {
			$rows[] = [
				'field' => $field,
				'value' => $value,
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );

		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of hits, 2: seconds taken. */
				__( '%1$s hits counted in %2$ss.', 'honest-analytics' ),
				number_format_i18n( $result->hits ),
				number_format_i18n( $result->seconds, 2 )
			)
		);
	}
}
