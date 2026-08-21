<?php
/**
 * Report screen state.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Stats\DateRange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything a report screen reads from the URL.
 *
 * State lives in the query string rather than in a session or a user option, so
 * a filtered, compared, date-bounded report is a link somebody can bookmark or
 * send to a colleague. That is the whole reason the comparison checkboxes post
 * to a GET form.
 *
 * Nothing here is trusted. These values arrive from anybody who can load an
 * admin URL, and each is validated for what it means before it reaches a query.
 */
final class RequestParams {

	public readonly string $screen;
	public readonly DateRange $range;
	public readonly string $include;
	public readonly string $exclude;
	public readonly string $tab;
	public readonly string $path;

	/**
	 * Paths ticked for comparison.
	 *
	 * @var string[]
	 */
	public readonly array $compare;

	/**
	 * @param string $screen Page slug.
	 */
	public function __construct( string $screen ) {
		$this->screen = $screen;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report state, no side effects.
		$rangeParam = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['range'] ) ) : '';
		$from       = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		$to         = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';

		// The custom picker posts two fields once; every link afterwards carries
		// the combined token, so there is only one shape of URL to reason about.
		$this->range = ( '' !== $from && '' !== $to )
			? DateRange::custom( $from, $to )
			: DateRange::fromParam( '' !== $rangeParam ? $rangeParam : '30d' );

		$this->include = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$this->exclude = isset( $_GET['exclude'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['exclude'] ) ) : '';
		$this->tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$this->path    = isset( $_GET['path'] ) ? esc_url_raw( wp_unslash( (string) $_GET['path'] ) ) : '';

		// Sanitized member by member below, then checked against the rows
		// actually on the screen - a path being well formed is not the same as
		// the reader being allowed to see its numbers.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$compare = isset( $_GET['compare'] ) ? wp_unslash( $_GET['compare'] ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->compare = is_array( $compare )
			? array_slice(
				array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $compare ) ) ) ),
				0,
				ChartData::MAX_SERIES
			)
			: [];
	}

	/**
	 * The state that should follow the reader from screen to screen.
	 *
	 * @return array<string,string>
	 */
	public function carried(): array {
		$carried = [ 'range' => $this->range->param ];

		foreach ( [
			'q'       => $this->include,
			'exclude' => $this->exclude,
			'tab'     => $this->tab,
		] as $key => $value ) {
			if ( '' !== $value ) {
				$carried[ $key ] = $value;
			}
		}

		return $carried;
	}

	/**
	 * A URL for this screen, with overrides applied.
	 *
	 * @param array<string,string|null> $overrides Parameters to add or remove.
	 * @param string|null               $screen    A different screen slug.
	 */
	public function url( array $overrides = [], ?string $screen = null ): string {
		$args = array_merge( $this->carried(), $overrides );

		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				unset( $args[ $key ] );
			}
		}

		return add_query_arg( $args, menu_page_url( $screen ?? $this->screen, false ) );
	}

	/**
	 * Only the paths on screen may be compared.
	 *
	 * Without this check the parameter would answer "does this path exist on a
	 * site you can see the report for", one guess at a time.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows currently listed.
	 *
	 * @return string[]
	 */
	public function validatedComparePaths( array $rows ): array {
		if ( [] === $this->compare ) {
			return [];
		}

		$available = [];

		foreach ( $rows as $row ) {
			$path = (string) ( $row['path'] ?? '' );

			if ( '' !== $path ) {
				$available[ $path ] = true;
			}
		}

		$valid = [];

		foreach ( $this->compare as $path ) {
			if ( isset( $available[ $path ] ) ) {
				$valid[] = $path;
			}
		}

		return $valid;
	}
}
