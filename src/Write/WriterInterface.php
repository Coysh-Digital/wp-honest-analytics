<?php
/**
 * Hit writing contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\Hit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a hit gets from a web request towards the totals.
 *
 * Implementations must never throw. A page that renders but does not get
 * counted is a missing number; a page that fatals because analytics could not
 * write a line is a broken site.
 */
interface WriterInterface {

	/**
	 * Write a hit.
	 *
	 * @param Hit $hit The hit.
	 */
	public function write( Hit $hit ): void;

	/**
	 * The driver name, for diagnostics.
	 */
	public function name(): string;
}
