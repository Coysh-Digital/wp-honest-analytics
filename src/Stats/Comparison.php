<?php
/**
 * Comparing one period's figures against another's.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one arithmetic a KPI card needs, done once.
 *
 * Every screen that shows "this period vs that one" used the same three lines
 * inline, in more than one place, with more than one chance to disagree about
 * what a zero baseline means.
 */
final class Comparison {

	/**
	 * The percentage change between two figures.
	 *
	 * Null rather than "+100%" when there was nothing to compare against:
	 * growth from zero is not a percentage.
	 *
	 * @param int|float $current  This period.
	 * @param int|float $previous The period before.
	 */
	public static function delta( int|float $current, int|float $previous ): ?float {
		if ( $previous <= 0 ) {
			return null;
		}

		return ( $current - $previous ) / $previous * 100;
	}

	/**
	 * Pair two periods' row-sets by a shared key, and attach a delta for
	 * each named field.
	 *
	 * Matched by key, not by position: the top rows in one period are not
	 * guaranteed to be the top rows - or even present at all - in another.
	 * A row with no counterpart in the comparison period gets a null delta
	 * for every field, the same "nothing to compare against" null
	 * {@see delta()} already returns for a zero baseline, rather than being
	 * dropped or compared against zero.
	 *
	 * @param array<int,array<string,mixed>> $current  This period's rows.
	 * @param array<int,array<string,mixed>> $previous The comparison period's rows.
	 * @param string                         $key      Field both row-sets are keyed by.
	 * @param string[]                       $fields   Which numeric fields get a delta.
	 *
	 * @return array<int,array<string,mixed>> `$current`, each row gaining a `"{$field}_delta"` key.
	 */
	public static function rows( array $current, array $previous, string $key, array $fields ): array {
		$byKey = [];

		foreach ( $previous as $row ) {
			$byKey[ (string) ( $row[ $key ] ?? '' ) ] = $row;
		}

		foreach ( $current as &$row ) {
			$match = $byKey[ (string) ( $row[ $key ] ?? '' ) ] ?? null;

			foreach ( $fields as $field ) {
				$row[ $field . '_delta' ] = null !== $match
					? self::delta( (float) ( $row[ $field ] ?? 0 ), (float) ( $match[ $field ] ?? 0 ) )
					: null;
			}
		}

		unset( $row );

		return $current;
	}
}
