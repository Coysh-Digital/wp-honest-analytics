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
}
