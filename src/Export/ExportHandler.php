<?php
/**
 * The export download.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Export;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Stats\DateRange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the Export button into a file.
 *
 * Taking data out of a site is a separate decision from being allowed to look
 * at it, which is why it has its own capability. Three checks stand between the
 * button and the download: the capability, a nonce, and a whitelist of kinds -
 * the last because the kind names a report, and a query parameter that names a
 * report is a query parameter somebody will try to rename.
 *
 * The route is `admin-post.php` rather than a screen, so the response is a file
 * and nothing else: no admin chrome, no notices, and no chance of a stray
 * `echo` from another plugin ending up inside the CSV.
 */
final class ExportHandler {

	public const ACTION = 'honest_analytics_export';
	public const NONCE  = 'honest-analytics-export';

	/**
	 * The formats the button offers.
	 *
	 * @var string[]
	 */
	private const FORMATS = [ 'csv', 'json' ];

	/**
	 * Attach the handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * The download URL for one report.
	 *
	 * @param string    $kind   Export kind, from Screen::exportKind().
	 * @param DateRange $range  Period being looked at.
	 * @param string    $format 'csv' or 'json'.
	 */
	public static function url( string $kind, DateRange $range, string $format = 'csv' ): string {
		$url = add_query_arg(
			[
				'action' => self::ACTION,
				'kind'   => $kind,
				'range'  => $range->param,
				'format' => in_array( $format, self::FORMATS, true ) ? $format : 'csv',
			],
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::NONCE );
	}

	/**
	 * Send the file.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Capabilities::EXPORT ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export analytics for this site.', 'honest-analytics' ),
				esc_html__( 'Not allowed', 'honest-analytics' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( self::NONCE );

		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '';

		if ( ! Exporter::supports( $kind ) ) {
			wp_die(
				esc_html__( 'There is no such report to export.', 'honest-analytics' ),
				esc_html__( 'Not found', 'honest-analytics' ),
				[ 'response' => 400 ]
			);
		}

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'csv';
		$format = in_array( $format, self::FORMATS, true ) ? $format : 'csv';

		$param = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['range'] ) ) : '30d';
		$range = DateRange::fromParam( $param );

		$exporter = Exporter::make();
		$rows     = $exporter->rows( $kind, get_current_blog_id(), $range );

		/**
		 * Filters the rows about to be exported.
		 *
		 * @param array<int,array<string,mixed>> $rows  Rows.
		 * @param string                         $kind  Export kind.
		 * @param DateRange                      $range Period.
		 */
		$rows = (array) apply_filters( 'honest_analytics_export_rows', $rows, $kind, $range );

		if ( 'json' === $format ) {
			self::sendJson( $kind, $range, $rows, $exporter );
		}

		self::sendCsv( $kind, $range, $rows );
	}

	/**
	 * Send the CSV.
	 *
	 * @param string                         $kind  Export kind.
	 * @param DateRange                      $range Period.
	 * @param array<int,array<string,mixed>> $rows  Rows.
	 */
	private static function sendCsv( string $kind, DateRange $range, array $rows ): void {
		self::sendHeaders( 'text/csv', self::filename( $kind, $range, 'csv' ) );

		// A byte order mark, purely for Excel on Windows: without it a path or
		// a search phrase containing anything outside ASCII opens as mojibake,
		// and the reader concludes the plugin mangled their data.
		echo "\xEF\xBB\xBF";

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download; Csv escapes every cell.
		echo Csv::encode( $rows );

		exit;
	}

	/**
	 * Send the JSON.
	 *
	 * @param string                         $kind     Export kind.
	 * @param DateRange                      $range    Period.
	 * @param array<int,array<string,mixed>> $rows     Rows.
	 * @param Exporter                       $exporter Exporter, for the metadata.
	 */
	private static function sendJson( string $kind, DateRange $range, array $rows, Exporter $exporter ): void {
		self::sendHeaders( 'application/json', self::filename( $kind, $range, 'json' ) );

		$payload = array_merge( $exporter->meta( $kind, $range ), [ 'rows' => array_values( $rows ) ] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded above.
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		exit;
	}

	/**
	 * The response headers a download needs.
	 *
	 * @param string $type     Content type.
	 * @param string $filename Suggested filename.
	 */
	private static function sendHeaders( string $type, string $filename ): void {
		// Anything still buffered - a notice from another plugin, a stray blank
		// line at the end of a file - would be the first bytes of the download.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();

		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * The filename.
	 *
	 * @param string    $kind   Export kind.
	 * @param DateRange $range  Period.
	 * @param string    $format File extension.
	 */
	public static function filename( string $kind, DateRange $range, string $format ): string {
		$site = sanitize_title( (string) get_bloginfo( 'name' ) );
		$site = '' === $site ? 'site' : $site;

		return sprintf( 'honest-analytics-%s-%s-%s-to-%s.%s', $site, $kind, $range->from, $range->to, $format );
	}
}
