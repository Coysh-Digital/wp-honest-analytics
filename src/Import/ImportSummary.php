<?php
/**
 * What an import would bring across.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The preview shown before anybody commits to anything.
 *
 * Counts here may be approximate and say so. A full count of a table with
 * fifteen million rows to draw a screen is not worth the wait, and "about 1.2
 * million page views" answers the question the person actually has.
 */
final class ImportSummary {

	/**
	 * @param string            $dateFrom    Earliest date available, Y-m-d.
	 * @param string            $dateTo      Latest date available, Y-m-d.
	 * @param array<string,int> $totals      Headline figures, keyed by label.
	 * @param bool              $approximate Whether those totals are estimates.
	 * @param string[]          $dimensions  What will be imported, in plain words.
	 * @param string[]          $excluded    What will not, and is worth naming.
	 * @param MetricMapping[]   $mappings    The metric-by-metric mapping.
	 * @param string[]          $notes       Anything else the person should read first.
	 */
	public function __construct(
		public readonly string $dateFrom,
		public readonly string $dateTo,
		public readonly array $totals = [],
		public readonly bool $approximate = true,
		public readonly array $dimensions = [],
		public readonly array $excluded = [],
		public readonly array $mappings = [],
		public readonly array $notes = []
	) {
	}

	/**
	 * How many days the available range covers.
	 */
	public function days(): int {
		if ( '' === $this->dateFrom || '' === $this->dateTo ) {
			return 0;
		}

		$from = strtotime( $this->dateFrom . ' 00:00:00 UTC' );
		$to   = strtotime( $this->dateTo . ' 00:00:00 UTC' );

		if ( false === $from || false === $to || $to < $from ) {
			return 0;
		}

		return (int) floor( ( $to - $from ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * Whether any mapping is only approximate.
	 *
	 * Drives the "your numbers may change" wording: a source whose every metric
	 * maps exactly deserves a quieter note than one whose visitor definition is
	 * a different thing wearing the same word.
	 */
	public function hasApproximateMappings(): bool {
		foreach ( $this->mappings as $mapping ) {
			if ( ! $mapping->isExact() ) {
				return true;
			}
		}

		return false;
	}
}
