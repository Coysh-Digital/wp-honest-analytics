<?php
/**
 * The garbage collection command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Gc\GcService;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact, expire and tidy.
 *
 * This is where the retention promises are actually kept, which is why it is a
 * command and not only a scheduled job: "we delete data after twenty-six
 * months" is a claim somebody should be able to run, watch, and count the rows
 * of.
 */
final class GcCommand {

	/**
	 * Compacts hourly rows into daily ones and deletes everything past its retention window.
	 *
	 * ## OPTIONS
	 *
	 * [--quiet]
	 * : Say nothing unless something went wrong.
	 *
	 * [--dry-run]
	 * : Count what would go, and delete none of it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics gc
	 *
	 *     # Before shortening a retention window, which cannot be undone.
	 *     $ wp honest-analytics gc --dry-run
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function __invoke( array $args, array $assocArgs = [] ): void {
		unset( $args );

		$service = new GcService( Plugin::instance()->settings() );
		$dryRun  = isset( $assocArgs['dry-run'] );
		$result  = $dryRun ? $service->preview() : $service->run();

		if ( isset( $assocArgs['quiet'] ) ) {
			return;
		}

		$rows = [];

		foreach ( $result as $field => $value ) {
			$rows[] = [
				'field' => $field,
				'value' => is_int( $value ) ? number_format_i18n( $value ) : (string) $value,
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );

		if ( $dryRun ) {
			\WP_CLI::success(
				sprintf(
					/* translators: %s: the earliest date that would be kept. */
					__( 'Nothing was deleted. A real run would keep nothing from before %s.', 'honest-analytics' ),
					$service->retentionCutoff( time() )
				)
			);

			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: %s: the earliest date still held. */
				__( 'Done. Nothing is now kept from before %s.', 'honest-analytics' ),
				$service->retentionCutoff( time() )
			)
		);
	}
}
