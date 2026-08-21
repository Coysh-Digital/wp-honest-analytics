<?php
/**
 * Whether there is anything here to import.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The answer to "have you used one of these before?".
 *
 * Deliberately not a boolean. "Not installed" and "installed but empty" and
 * "installed, and here is four years of it" are three different things to say
 * to somebody, and only one of them is worth a button.
 */
final class DetectionResult {

	/** Nothing of this source on this site. */
	public const NONE = 'none';

	/** Tables or an account exist, but there is no history in them. */
	public const EMPTY_SOURCE = 'empty';

	/** There is data, and it can be imported. */
	public const AVAILABLE = 'available';

	/** It is there, but this plugin cannot read it - an unknown schema, say. */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * @param string      $state       One of the constants above.
	 * @param string      $detail      A sentence for the screen, in plain language.
	 * @param string|null $dateFrom    Earliest date found, Y-m-d.
	 * @param string|null $dateTo      Latest date found, Y-m-d.
	 * @param int         $approxRows  Roughly how many source records, or 0 if not cheaply knowable.
	 * @param bool        $approximate Whether approxRows is an estimate rather than a count.
	 * @param string[]    $notes       Anything else worth saying before importing.
	 */
	public function __construct(
		public readonly string $state,
		public readonly string $detail = '',
		public readonly ?string $dateFrom = null,
		public readonly ?string $dateTo = null,
		public readonly int $approxRows = 0,
		public readonly bool $approximate = true,
		public readonly array $notes = []
	) {
	}

	/**
	 * Whether there is something worth offering a button for.
	 */
	public function isAvailable(): bool {
		return self::AVAILABLE === $this->state;
	}

	/**
	 * Whether this source is present at all, even if empty or unreadable.
	 */
	public function isPresent(): bool {
		return self::NONE !== $this->state;
	}

	/**
	 * Nothing here.
	 *
	 * @param string $detail Why, if it is worth saying.
	 */
	public static function none( string $detail = '' ): self {
		return new self( self::NONE, $detail );
	}
}
