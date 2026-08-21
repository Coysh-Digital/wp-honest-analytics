<?php
/**
 * CSV encoding.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A CSV a spreadsheet will open without executing anything.
 *
 * Two hazards, both of which look like pedantry right up until they bite, and
 * both of which are handled here rather than being left to whoever calls this.
 *
 * **Formula injection.** Every string in an analytics export came from a
 * visitor: a path they requested, a search phrase they typed, a referrer they
 * arrived with. Excel, LibreOffice and Google Sheets all treat a cell beginning
 * `=`, `+`, `-` or `@` as a formula, and `=HYPERLINK(...)` in a report an
 * administrator opens is a working attack. Those values get a leading
 * apostrophe, which every spreadsheet reads as "this is text" and no
 * spreadsheet displays. Leading whitespace is included in the check because
 * their parsers skip it before deciding.
 *
 * **The backslash escape.** PHP's `fputcsv()` defaults to a backslash escape
 * character, which is not part of the CSV convention and which no spreadsheet
 * expects. A value ending in a backslash is then written as `"a,b\"` and a
 * reader that honours the escape treats the closing quote as escaped and
 * swallows the rest of the file. Passing an empty escape turns it off and makes
 * the doubled-quote convention the only one in play.
 */
final class Csv {

	/**
	 * The characters a spreadsheet treats as the start of a formula.
	 *
	 * @var string[]
	 */
	private const FORMULA_LEAD_INS = [ '=', '+', '-', '@' ];

	/** What a spreadsheet skips before deciding whether a cell is a formula. */
	private const LEADING_WHITESPACE = " \t\r\n\0\x0B";

	/**
	 * Encode rows as CSV.
	 *
	 * The header comes from the first row's keys, and every row is written in
	 * that order - so a row with a missing key produces an empty cell rather
	 * than a shifted line.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	public static function encode( array $rows ): string {
		if ( [] === $rows ) {
			// Not even a header. An empty file is honest; a header on its own
			// suggests a query that ran and found nothing, which is a different
			// thing from a query that was never asked.
			return '';
		}

		$first = reset( $rows );

		if ( ! is_array( $first ) || [] === $first ) {
			return '';
		}

		$columns = array_keys( $first );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return '';
		}

		self::write( $handle, array_map( [ self::class, 'neutralise' ], $columns ) );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$line = [];

			foreach ( $columns as $column ) {
				$line[] = self::neutralise( $row[ $column ] ?? '' );
			}

			self::write( $handle, $line );
		}

		rewind( $handle );

		$csv = stream_get_contents( $handle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return false === $csv ? '' : $csv;
	}

	/**
	 * Make one value safe to put in a cell.
	 *
	 * Numbers are returned untouched and as numbers, because a report whose
	 * figures a spreadsheet cannot add up is not much of a report. A numeric
	 * string is left alone for the same reason: "-5" is a negative number, not
	 * a formula, and prefixing it would turn every negative figure in an export
	 * into text.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function neutralise( mixed $value ): mixed {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( '' === $value || is_numeric( $value ) ) {
			return $value;
		}

		$trimmed = ltrim( $value, self::LEADING_WHITESPACE );

		if ( '' === $trimmed ) {
			return $value;
		}

		return in_array( $trimmed[0], self::FORMULA_LEAD_INS, true ) ? "'" . $value : $value;
	}

	/**
	 * Write one line.
	 *
	 * @param resource         $handle Open stream.
	 * @param array<int,mixed> $line   Cells.
	 */
	private static function write( $handle, array $line ): void {
		// The empty escape character is the point of this wrapper: see the
		// class comment. Every call has to pass it, so there is one call.
		fputcsv( $handle, $line, ',', '"', '' );
	}
}
