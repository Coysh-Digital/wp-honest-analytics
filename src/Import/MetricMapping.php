<?php
/**
 * One source metric, and what it becomes here.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single line of the mapping table, with its honesty recorded.
 *
 * The `type` is the point of this class. Page views are page views everywhere,
 * near enough; a GA4 active user is emphatically not the same thing as a
 * visitor counted by a rotating daily salt. Marking which is which in the code
 * is what lets the interface say so without anybody having to remember.
 */
final class MetricMapping {

	/** The two systems mean the same thing by this. */
	public const EXACT = 'exact';

	/** Close enough to be useful, different enough to say so. */
	public const APPROXIMATE = 'approximate';

	/**
	 * @param string $sourceMetric What the other system calls it.
	 * @param string $metric       What this plugin calls it.
	 * @param string $type         EXACT or APPROXIMATE.
	 * @param string $note         Why, in a sentence somebody would read.
	 */
	public function __construct(
		public readonly string $sourceMetric,
		public readonly string $metric,
		public readonly string $type = self::EXACT,
		public readonly string $note = ''
	) {
	}

	/**
	 * Whether the two sides mean the same thing.
	 */
	public function isExact(): bool {
		return self::EXACT === $this->type;
	}

	/**
	 * For the details screen and the logs.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return [
			'sourceMetric' => $this->sourceMetric,
			'metric'       => $this->metric,
			'type'         => $this->type,
			'note'         => $this->note,
		];
	}
}
