<?php
/**
 * The privacy command.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Cli;

use HonestAnalytics\Plugin;
use HonestAnalytics\Privacy\PrivacyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subject access and erasure, from the command line.
 *
 * The same two operations WordPress's own privacy tools perform, available
 * without the admin, because a data subject request sometimes arrives while the
 * site is down and always arrives with a deadline.
 *
 * `posture` is the one to run before answering a question about compliance. It
 * reports what this installation is actually configured to collect, rather than
 * what the plugin is capable of collecting - those are different, and only the
 * first is true of this site.
 */
final class PrivacyCommand {

	/**
	 * Shows what this installation collects, and under what basis.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics privacy posture
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function posture( array $args, array $assocArgs = [] ): void {
		unset( $args, $assocArgs );

		$service = new PrivacyService( Plugin::instance()->settings() );
		$posture = $service->posture();

		\WP_CLI::log( \WP_CLI::colorize( '%B' . $posture->title() . '%n' ) );
		\WP_CLI::log( $posture->summary() );
		\WP_CLI::log( '' );

		\WP_CLI::log( __( 'Lawful basis:', 'honest-analytics' ) . ' ' . $posture->lawfulBasis() );
		\WP_CLI::log(
			__( 'Cookie banner needed:', 'honest-analytics' ) . ' ' .
			( $posture->needsBanner() ? __( 'yes', 'honest-analytics' ) : __( 'no', 'honest-analytics' ) )
		);

		\WP_CLI::log( '' );
		\WP_CLI::log( __( 'Collected:', 'honest-analytics' ) );

		foreach ( $posture->collects() as $item ) {
			\WP_CLI::log( '  - ' . ( is_array( $item ) ? implode( ': ', array_map( 'strval', $item ) ) : (string) $item ) );
		}

		foreach ( $posture->warnings() as $warning ) {
			\WP_CLI::warning( $warning );
		}

		$counts = $service->counts( get_current_blog_id() );
		$rows   = [];

		foreach ( $counts as $field => $value ) {
			$rows[] = [
				'field' => (string) $field,
				'value' => number_format_i18n( (int) $value ),
			];
		}

		\WP_CLI::log( '' );
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
	}

	/**
	 * Exports everything held about one subject.
	 *
	 * ## OPTIONS
	 *
	 * [--visitor-id=<id>]
	 * : The durable identifier from a consented visitor's cookie.
	 *
	 * [--user-id=<id>]
	 * : A WordPress user id.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics privacy export --user-id=12
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function export( array $args, array $assocArgs = [] ): void {
		unset( $args );

		[ $visitorId, $userId ] = $this->subject( $assocArgs );

		$data   = ( new PrivacyService( Plugin::instance()->settings() ) )->export( $visitorId, $userId );
		$format = 'yaml' === ( $assocArgs['format'] ?? 'json' ) ? 'yaml' : 'json';

		\WP_CLI::print_value( $data, [ 'format' => $format ] );
	}

	/**
	 * Erases everything held about one subject.
	 *
	 * The consent record is kept unless --include-consent-log is given, and that
	 * is deliberate: it is the evidence that the processing just erased was
	 * lawful while it was happening.
	 *
	 * ## OPTIONS
	 *
	 * [--visitor-id=<id>]
	 * : The durable identifier from a consented visitor's cookie.
	 *
	 * [--user-id=<id>]
	 * : A WordPress user id.
	 *
	 * [--include-consent-log]
	 * : Also delete the record of what they consented to.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp honest-analytics privacy erase --visitor-id=9f8c... --yes
	 *
	 * @param array<int,string>    $args      Positional arguments.
	 * @param array<string,string> $assocArgs Options.
	 */
	public function erase( array $args, array $assocArgs = [] ): void {
		unset( $args );

		[ $visitorId, $userId ] = $this->subject( $assocArgs );

		\WP_CLI::confirm( __( 'This deletes the subject\'s journeys permanently. Continue?', 'honest-analytics' ), $assocArgs );

		$result = ( new PrivacyService( Plugin::instance()->settings() ) )->erase(
			$visitorId,
			$userId,
			isset( $assocArgs['include-consent-log'] )
		);

		\WP_CLI::success(
			sprintf(
				/* translators: 1: journey rows removed, 2: consent rows removed. */
				__( '%1$d journey row(s) and %2$d consent record(s) deleted.', 'honest-analytics' ),
				$result['journeys'],
				$result['consentLog']
			)
		);

		\WP_CLI::log( __( 'Aggregate counters are untouched, and contain nothing about this person to remove.', 'honest-analytics' ) );
	}

	/**
	 * Read the subject from the options, or stop.
	 *
	 * @param array<string,string> $assocArgs Options.
	 *
	 * @return array{0:string|null,1:int|null}
	 */
	private function subject( array $assocArgs ): array {
		$visitorId = isset( $assocArgs['visitor-id'] ) ? (string) $assocArgs['visitor-id'] : null;
		$userId    = isset( $assocArgs['user-id'] ) ? (int) $assocArgs['user-id'] : null;

		if ( null === $visitorId && null === $userId ) {
			\WP_CLI::error( __( 'Name a subject: --visitor-id=<id> or --user-id=<id>.', 'honest-analytics' ) );
		}

		return [ $visitorId, $userId ];
	}
}
