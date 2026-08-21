<?php
/**
 * Direct writer.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Write;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates on the spot, with no scheduled command to run.
 *
 * The simplest to set up and the first thing to fall over.
 */
final class DirectWriter implements WriterInterface {

	/**
	 * @param HitApplier $applier Hit applier.
	 */
	public function __construct( private HitApplier $applier ) {
	}

	/**
	 * Apply a hit immediately.
	 *
	 * @param Hit $hit The hit.
	 */
	public function write( Hit $hit ): void {
		$this->applier->apply( $hit );
	}

	/**
	 * The driver name.
	 */
	public function name(): string {
		return Settings::WRITE_DIRECT;
	}
}
