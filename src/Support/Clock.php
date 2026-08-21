<?php
/**
 * Injectable clock.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The current time, as a value the tests can control.
 */
class Clock {

	/**
	 * Fixed timestamp, when the clock has been frozen.
	 *
	 * @var int|null
	 */
	private ?int $frozen;

	/**
	 * @param int|null $frozen Fixed timestamp, or null for the real clock.
	 */
	public function __construct( ?int $frozen = null ) {
		$this->frozen = $frozen;
	}

	/**
	 * Current unix timestamp.
	 */
	public function now(): int {
		return $this->frozen ?? time();
	}
}
