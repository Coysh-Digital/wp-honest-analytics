<?php
/**
 * The report command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Email\ReportMailer;
use HonestAnalytics\Export\Csv;
use HonestAnalytics\Export\Exporter;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read a report without opening a browser.
 *
 * The same queries the screens run and the same rows the export button
 * produces, which makes this the honest way to check a figure somebody is
 * disputing: if the command and the screen disagree, one of them is wrong and
 * it is worth knowing which.
 */
final class ReportCommand {

	/**
	 * Prints a report, or emails the scheduled summary.
	 *
	 * ## OPTIONS
	 *
	 * [<kind>]
	 * : Which report. One of: trend, pages, sources, devices, content.
	 * ---
	 * default: pages
	 * ---
	 *
	 * [--range=<range>]
	 * : A preset such as 7d or 30d, or a YYYY-MM-DD:YYYY-MM-DD span.
	 * ---
	 * default: 30d
	 * ---
	 *
	 * [--limit=<number>]
	 * : How many rows to print.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--email]
	 * : Send the scheduled summary email now instead of printing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics report pages --range=7d
	 *
	 *     $ wp honest-analytics report trend --range=2026-01-01:2026-01-31 --format=csv
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function __invoke( array $args, array $assocArgs = [] ): void {
		$range = DateRange::fromParam( (string) ( $assocArgs['range'] ?? '30d' ) );

		if ( isset( $assocArgs['email'] ) ) {
			$this->email( $range );

			return;
		}

		$kind = (string) ( $args[0] ?? 'pages' );

		if ( ! Exporter::supports( $kind ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %s: comma separated list of report names. */
					__( 'There is no report called that. Try one of: %s', 'honest-analytics' ),
					implode( ', ', Exporter::KINDS )
				)
			);

			return;
		}

		$exporter = Exporter::make();
		$rows     = $exporter->rows( $kind, get_current_blog_id(), $range );
		$limit    = max( 1, (int) ( $assocArgs['limit'] ?? 20 ) );
		$format   = (string) ( $assocArgs['format'] ?? 'table' );

		if ( [] === $rows ) {
			\WP_CLI::warning( __( 'Nothing was recorded in that period.', 'honest-analytics' ) );

			return;
		}

		$rows = array_slice( $rows, 0, $limit );

		if ( 'csv' === $format ) {
			// Written straight out rather than through WP-CLI's own CSV
			// formatter, so a redirected file gets the same formula-defused
			// cells the download does.
			\WP_CLI::log( Csv::encode( $rows ) );

			return;
		}

		if ( 'json' === $format ) {
			\WP_CLI::print_value( array_merge( $exporter->meta( $kind, $range ), [ 'rows' => $rows ] ), [ 'format' => 'json' ] );

			return;
		}

		\WP_CLI::log( $exporter->label( $kind ) . ' - ' . $range->label );

		// A row keyed `2026` arrives from array_keys() as an int, and
		// format_items() indexes each row by the field name as a string.
		$fields = array_map( 'strval', array_keys( (array) reset( $rows ) ) );

		\WP_CLI\Utils\format_items( 'table', $rows, $fields );

		\WP_CLI::log(
			sprintf(
				/* translators: %s: accuracy, for instance "±1.6%". */
				__( 'Unique visitor figures are per-day estimates, accurate to about %s, and cannot be added across days.', 'honest-analytics' ),
				Plugin::instance()->stats()->uniquesAccuracy()
			)
		);
	}

	/**
	 * Send the scheduled summary now.
	 *
	 * @param DateRange $range Period.
	 */
	private function email( DateRange $range ): void {
		$settings = Plugin::instance()->settings();

		// Scheduled summaries are Pro, and the free build ships without the
		// mailer at all rather than with it switched off.
		if ( ! Edition::isPro() || ! class_exists( ReportMailer::class ) ) {
			\WP_CLI::error( __( 'Scheduled summaries are part of Honest Analytics Pro.', 'honest-analytics' ) );

			return;
		}

		if ( [] === ReportMailer::recipients( $settings ) ) {
			\WP_CLI::error( __( 'No valid recipients are configured on the Settings screen.', 'honest-analytics' ) );

			return;
		}

		if ( ! ReportMailer::send( $settings, $range ) ) {
			\WP_CLI::error( __( 'The email could not be sent. Check this site can send mail at all.', 'honest-analytics' ) );

			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: %s: comma separated email addresses. */
				__( 'Sent to %s.', 'honest-analytics' ),
				implode( ', ', ReportMailer::recipients( $settings ) )
			)
		);
	}
}
