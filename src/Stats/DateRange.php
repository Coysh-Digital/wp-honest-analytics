<?php
/**
 * Report date ranges.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

use DateTimeImmutable;
use DateTimeZone;
use HonestAnalytics\Support\Timezone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The period a report covers.
 *
 * Resolved in the site's own timezone, never UTC, because the rollups are keyed
 * by local date and "yesterday" has to mean the same thing to a query as it
 * does to the person reading it.
 *
 * Nothing here throws. The range arrives from a query string, which is to say
 * from anybody, and an unreadable one falls back to thirty days rather than
 * showing somebody an error page. Only the CLI is strict, because a mistyped
 * `--period` on a scheduled email should complain rather than quietly report on
 * the wrong month.
 */
final class DateRange {

	public const PRESET_CUSTOM = 'custom';

	/** A guard on a parameter anybody can set. */
	public const MAX_CUSTOM_DAYS = 400;

	public function __construct(
		public readonly string $from,
		public readonly string $to,
		public readonly string $preset,
		public readonly string $param,
		public readonly string $label
	) {
	}

	/**
	 * The named ranges offered in the toolbar.
	 *
	 * `custom` is deliberately absent: it cannot be saved as a scheduled report
	 * period or a widget setting, because a saved range with fixed dates stops
	 * being true the day after it is saved.
	 *
	 * @return array<string,string>
	 */
	public static function presets(): array {
		return [
			'today'      => __( 'Today', 'honest-analytics' ),
			'yesterday'  => __( 'Yesterday', 'honest-analytics' ),
			'7d'         => __( 'Last 7 days', 'honest-analytics' ),
			'30d'        => __( 'Last 30 days', 'honest-analytics' ),
			'90d'        => __( 'Last 90 days', 'honest-analytics' ),
			'this-month' => __( 'This month', 'honest-analytics' ),
			'last-month' => __( 'Last month', 'honest-analytics' ),
			'12mo'       => __( 'Last 12 months', 'honest-analytics' ),
		];
	}

	/**
	 * Build a range from a preset handle.
	 *
	 * An unknown preset quietly becomes thirty days.
	 *
	 * @param string            $preset   Preset handle.
	 * @param int|null          $now      Timestamp.
	 * @param DateTimeZone|null $timezone Timezone override.
	 */
	public static function fromPreset( string $preset, ?int $now = null, ?DateTimeZone $timezone = null ): self {
		$today  = self::today( $now, $timezone );
		$labels = self::presets();

		[ $from, $to ] = match ( $preset ) {
			'today'      => [ $today, $today ],
			'yesterday'  => [ $today->modify( '-1 day' ), $today->modify( '-1 day' ) ],
			'7d'         => [ $today->modify( '-6 days' ), $today ],
			'90d'        => [ $today->modify( '-89 days' ), $today ],
			// The calendar month, not a rolling window - "This month" runs to
			// today rather than to a future month-end, and "Last month" is the
			// whole of the one before, because it is already over.
			'this-month' => [ $today->modify( 'first day of this month' ), $today ],
			'last-month' => [ $today->modify( 'first day of last month' ), $today->modify( 'last day of last month' ) ],
			'12mo'       => [ $today->modify( '-1 year' )->modify( '+1 day' ), $today ],
			default      => [ $today->modify( '-29 days' ), $today ],
		};

		$handle = isset( $labels[ $preset ] ) ? $preset : '30d';

		return new self(
			$from->format( 'Y-m-d' ),
			$to->format( 'Y-m-d' ),
			$handle,
			$handle,
			$labels[ $handle ]
		);
	}

	/**
	 * Build a range from a URL parameter.
	 *
	 * Accepts a preset handle or a `YYYY-MM-DD:YYYY-MM-DD` token.
	 *
	 * @param string            $value    Parameter value.
	 * @param int|null          $now      Timestamp.
	 * @param DateTimeZone|null $timezone Timezone override.
	 */
	public static function fromParam( string $value, ?int $now = null, ?DateTimeZone $timezone = null ): self {
		$token = self::parseToken( $value, $timezone );

		if ( null !== $token ) {
			return self::custom( $token[0], $token[1], $now, $timezone );
		}

		return self::fromPreset( $value, $now, $timezone );
	}

	/**
	 * Build a custom range, clamped into something answerable.
	 *
	 * The clamping order matters and is deliberate: a reversed range is swapped
	 * rather than rejected, an end in the future is pulled back to today, and an
	 * over-long span keeps its *end* and moves its start forward - because
	 * somebody asking for "the last two years" wants the recent end of it.
	 *
	 * @param string            $from     Start date.
	 * @param string            $to       End date.
	 * @param int|null          $now      Timestamp.
	 * @param DateTimeZone|null $timezone Timezone override.
	 */
	public static function custom( string $from, string $to, ?int $now = null, ?DateTimeZone $timezone = null ): self {
		$start = self::parseDate( $from, $timezone );
		$end   = self::parseDate( $to, $timezone );

		if ( null === $start || null === $end ) {
			return self::fromPreset( '30d', $now, $timezone );
		}

		if ( $start > $end ) {
			[ $start, $end ] = [ $end, $start ];
		}

		$today = self::today( $now, $timezone );

		if ( $end > $today ) {
			$end = $today;
		}

		$earliest = $end->modify( '-' . ( self::MAX_CUSTOM_DAYS - 1 ) . ' days' );

		if ( $start < $earliest ) {
			$start = $earliest;
		}

		return new self(
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' ),
			self::PRESET_CUSTOM,
			$start->format( 'Y-m-d' ) . ':' . $end->format( 'Y-m-d' ),
			sprintf(
				/* translators: 1: start date, 2: end date. */
				__( '%1$s to %2$s', 'honest-analytics' ),
				$start->format( 'j M Y' ),
				$end->format( 'j M Y' )
			)
		);
	}

	/**
	 * The equivalent period immediately before this one.
	 *
	 * The preset is kept for provenance but the parameter is recomputed: a
	 * "previous period" link that still said `7d` would send the reader to the
	 * current seven days rather than the seven being compared against.
	 */
	public function previous(): self {
		$timezone = Timezone::site();
		$start    = self::parseDate( $this->from, $timezone );
		$end      = self::parseDate( $this->to, $timezone );

		if ( null === $start || null === $end ) {
			return $this;
		}

		$days = (int) $start->diff( $end )->format( '%a' ) + 1;

		$previousStart = $start->modify( '-' . $days . ' days' );
		$previousEnd   = $end->modify( '-' . $days . ' days' );

		return new self(
			$previousStart->format( 'Y-m-d' ),
			$previousEnd->format( 'Y-m-d' ),
			$this->preset,
			$previousStart->format( 'Y-m-d' ) . ':' . $previousEnd->format( 'Y-m-d' ),
			__( 'Previous period', 'honest-analytics' )
		);
	}

	/**
	 * The same period, one calendar year earlier.
	 *
	 * Not preset-aware: this shifts both ends back exactly a year, the same way
	 * {@see previous()} recomputes the parameter rather than preserving preset
	 * semantics. A range ending on 29 February rolls forward to 1 March in the
	 * target year, because that is what `modify('-1 year')` does and pretending
	 * otherwise would only make the one time in four it matters harder to spot.
	 */
	public function sameLastYear(): self {
		$timezone = Timezone::site();
		$start    = self::parseDate( $this->from, $timezone );
		$end      = self::parseDate( $this->to, $timezone );

		if ( null === $start || null === $end ) {
			return $this;
		}

		$lastYearStart = $start->modify( '-1 year' );
		$lastYearEnd   = $end->modify( '-1 year' );

		return new self(
			$lastYearStart->format( 'Y-m-d' ),
			$lastYearEnd->format( 'Y-m-d' ),
			$this->preset,
			$lastYearStart->format( 'Y-m-d' ) . ':' . $lastYearEnd->format( 'Y-m-d' ),
			__( 'Same period last year', 'honest-analytics' )
		);
	}

	/**
	 * Whether the range crosses a calendar year boundary.
	 *
	 * Drives whether a chart needs to say the year out loud: a range entirely
	 * inside one year does not, and one that spans two or more does, on every
	 * axis label, or a point in December and a point in January the following
	 * year look adjacent instead of eleven months apart.
	 */
	public function spansMultipleYears(): bool {
		return substr( $this->from, 0, 4 ) !== substr( $this->to, 0, 4 );
	}

	/**
	 * How many days the range covers, inclusive.
	 */
	public function days(): int {
		$timezone = Timezone::site();
		$start    = self::parseDate( $this->from, $timezone );
		$end      = self::parseDate( $this->to, $timezone );

		if ( null === $start || null === $end ) {
			return 1;
		}

		return (int) $start->diff( $end )->format( '%a' ) + 1;
	}

	/**
	 * Whether the range is a single day, and therefore has an hourly axis.
	 */
	public function isHourly(): bool {
		return 1 === $this->days();
	}

	/**
	 * Every date in the range, so charts can zero-fill.
	 *
	 * @return string[]
	 */
	public function dates(): array {
		$timezone = Timezone::site();
		$start    = self::parseDate( $this->from, $timezone );
		$end      = self::parseDate( $this->to, $timezone );

		if ( null === $start || null === $end ) {
			return [];
		}

		$dates   = [];
		$current = $start;

		while ( $current <= $end ) {
			$dates[] = $current->format( 'Y-m-d' );
			$current = $current->modify( '+1 day' );
		}

		return $dates;
	}

	/**
	 * Whether the range includes today.
	 *
	 * The dividing line for caching: a finished period can be remembered, and a
	 * period still being added to cannot.
	 *
	 * @param int|null $now Timestamp.
	 */
	public function includesToday( ?int $now = null ): bool {
		return $this->to >= self::today( $now )->format( 'Y-m-d' );
	}

	/**
	 * Whether a parameter value is one we recognise.
	 *
	 * Used where being wrong should be an error rather than a fallback.
	 *
	 * @param string $value Parameter value.
	 */
	public static function isValidParam( string $value ): bool {
		return isset( self::presets()[ $value ] ) || null !== self::parseToken( $value );
	}

	/**
	 * Midnight today, in the site's timezone.
	 *
	 * @param int|null          $now      Timestamp.
	 * @param DateTimeZone|null $timezone Timezone override.
	 */
	private static function today( ?int $now = null, ?DateTimeZone $timezone = null ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )
			->setTimezone( $timezone ?? Timezone::site() )
			->setTime( 0, 0 );
	}

	/**
	 * Split a `from:to` token.
	 *
	 * @param string            $value    Token.
	 * @param DateTimeZone|null $timezone Timezone override.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function parseToken( string $value, ?DateTimeZone $timezone = null ): ?array {
		$parts = explode( ':', $value );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		if ( null === self::parseDate( $parts[0], $timezone ) || null === self::parseDate( $parts[1], $timezone ) ) {
			return null;
		}

		return [ $parts[0], $parts[1] ];
	}

	/**
	 * Parse a Y-m-d date, strictly.
	 *
	 * The round-trip check is what rejects 30 February, which
	 * `createFromFormat` would happily roll forward into March.
	 *
	 * @param string            $value    Date string.
	 * @param DateTimeZone|null $timezone Timezone override.
	 */
	private static function parseDate( string $value, ?DateTimeZone $timezone = null ): ?DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone ?? Timezone::site() );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $date;
	}
}
