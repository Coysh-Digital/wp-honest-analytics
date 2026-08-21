<?php
/**
 * Unique-visitor counting contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Uniques;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How distinct visitors are counted.
 *
 * One rule binds every implementation: a range is the **union** of its parts,
 * never the sum. Adding up daily unique visitors to produce a monthly figure
 * counts a regular reader once per visit and inflates the number by however
 * loyal the audience is. Every implementation must merge, and the accuracy it
 * reports must never be oversold.
 */
interface UniqueCounterInterface {

	/**
	 * Add visitor hashes to a scope.
	 *
	 * @param UniqueScope $scope   Scope.
	 * @param string[]    $hashes  Visitor hashes.
	 * @param string|null $current The sketch already stored on the row, if the
	 *                             implementation keeps it there.
	 *
	 * @return string|null The blob to store back, or null when this
	 *                     implementation stores elsewhere.
	 */
	public function record( UniqueScope $scope, array $hashes, ?string $current ): ?string;

	/**
	 * Estimate the distinct visitors across a set of scopes.
	 *
	 * @param UniqueScope[]             $scopes   Scopes to union.
	 * @param array<string,string|null> $sketches Stored sketches, keyed by scope key.
	 */
	public function estimate( array $scopes, array $sketches ): int;

	/**
	 * Whether this implementation keeps its data on the rollup row.
	 */
	public function storesOnRow(): bool;

	/**
	 * Fold hourly scopes into a daily one.
	 *
	 * @param UniqueScope   $daily  Destination.
	 * @param UniqueScope[] $hourly Sources.
	 */
	public function compact( UniqueScope $daily, array $hourly ): void;

	/**
	 * Discard scopes that have been folded away.
	 *
	 * @param UniqueScope[] $scopes Scopes.
	 */
	public function discardCompacted( array $scopes ): void;

	/**
	 * How accurate this implementation is, in words a report can print.
	 */
	public function accuracy(): string;

	/**
	 * The implementation name, used in cache keys and diagnostics.
	 */
	public function name(): string;
}
