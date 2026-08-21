<?php
/**
 * What the user asked for.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The choices made on the wizard, in a form that survives a restart.
 *
 * Serialised into the job row, so a migration that resumes three days later
 * resumes with the same answers rather than with today's defaults.
 */
final class ImportConfiguration {

	/** Skip any day another import already covers. The default, and the safe one. */
	public const OVERLAP_SKIP = 'skip';

	/** Replace the other source's figures for the days that clash. */
	public const OVERLAP_REPLACE = 'replace';

	/**
	 * @param string              $dateFrom Y-m-d, inclusive.
	 * @param string              $dateTo   Y-m-d, inclusive.
	 * @param string              $overlap  One of the OVERLAP_ constants.
	 * @param array<string,mixed> $options  Anything the importer needs, such as a GA4 property.
	 */
	public function __construct(
		public readonly string $dateFrom,
		public readonly string $dateTo,
		public readonly string $overlap = self::OVERLAP_SKIP,
		public readonly array $options = []
	) {
	}

	/**
	 * One option, or a fallback.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Returned when absent.
	 */
	public function option( string $key, mixed $default = null ): mixed {
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return [
			'dateFrom' => $this->dateFrom,
			'dateTo'   => $this->dateTo,
			'overlap'  => $this->overlap,
			'options'  => $this->options,
		];
	}

	/**
	 * Rebuild from a stored row.
	 *
	 * @param array<string,mixed> $data Decoded JSON.
	 */
	public static function fromArray( array $data ): self {
		$overlap = (string) ( $data['overlap'] ?? self::OVERLAP_SKIP );

		return new self(
			(string) ( $data['dateFrom'] ?? '' ),
			(string) ( $data['dateTo'] ?? '' ),
			in_array( $overlap, [ self::OVERLAP_SKIP, self::OVERLAP_REPLACE ], true ) ? $overlap : self::OVERLAP_SKIP,
			is_array( $data['options'] ?? null ) ? $data['options'] : []
		);
	}
}
