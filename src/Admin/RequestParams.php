<?php
/**
 * Report screen state.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Charts\ChartData;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\DateRange;
use HonestAnalytics\Stats\Granularity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything a report screen reads from the URL.
 *
 * State lives in the query string rather than in a session or a user option, so
 * a filtered, compared, date-bounded report is a link somebody can bookmark or
 * send to a colleague. That is the whole reason the comparison checkboxes post
 * to a GET form.
 *
 * Range, granularity and the comparison period are the one exception: they are
 * also remembered per user, so that clicking a bare sidebar link - which
 * carries no query string at all - lands on whatever was last chosen rather
 * than resetting to thirty days. An explicit query parameter always wins over
 * the stored preference, and the preference is only ever written when the
 * request explicitly supplied the value being written, never when it merely
 * fell back to what was already stored - otherwise the second visit to a
 * bookmarked link would already have forgotten what the link asked for.
 *
 * Nothing here is trusted. These values arrive from anybody who can load an
 * admin URL, and each is validated for what it means before it reaches a query.
 */
final class RequestParams {

	/** No comparison period, previous period, or the same period last year. */
	private const COMPARE_PERIODS = [ '', 'previous', 'year' ];

	/** Where the per-user fallback is remembered. */
	private const USER_META = 'honest_analytics_report_prefs';

	public readonly string $screen;
	public readonly DateRange $range;
	public readonly Granularity $granularity;

	/**
	 * Which period this range is being compared against, if any.
	 *
	 * Deliberately not named `compare` - that name is already taken by the
	 * unrelated page-to-page comparison below, and the two must never be
	 * confused in code or in a URL.
	 */
	public readonly string $comparePeriod;

	public readonly string $include;
	public readonly string $exclude;
	public readonly string $tab;
	public readonly string $path;

	/**
	 * Paths ticked for comparison.
	 *
	 * @var string[]
	 */
	public readonly array $compare;

	/**
	 * @param string $screen Page slug.
	 */
	public function __construct( string $screen ) {
		$this->screen = $screen;

		$stored = self::storedPreferences();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report state, no side effects.
		$rangeParam = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['range'] ) ) : '';
		$from       = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		$to         = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';

		// A custom range is never remembered - a saved range with fixed dates
		// stops being true the day after it is saved (the same reasoning
		// DateRange::presets() already applies to excluding `custom`).
		$hasExplicitRange = '' !== $rangeParam || ( '' !== $from && '' !== $to );

		// The custom picker posts two fields once; every link afterwards carries
		// the combined token, so there is only one shape of URL to reason about.
		$this->range = ( '' !== $from && '' !== $to )
			? DateRange::custom( $from, $to )
			: DateRange::fromParam( '' !== $rangeParam ? $rangeParam : $stored['range'], null, null, self::earliestRecorded() );

		$hasExplicitGranularity = isset( $_GET['granularity'] );
		$granularityParam       = $hasExplicitGranularity ? sanitize_key( wp_unslash( (string) $_GET['granularity'] ) ) : $stored['granularity'];
		$this->granularity      = Granularity::clamp( Granularity::fromParam( $granularityParam ), $this->range );

		$hasExplicitComparePeriod = isset( $_GET['compare_period'] );
		$comparePeriodParam       = $hasExplicitComparePeriod
			? self::normalizeComparePeriod( sanitize_key( wp_unslash( (string) $_GET['compare_period'] ) ) )
			: $stored['compare_period'];
		$this->comparePeriod      = in_array( $comparePeriodParam, self::COMPARE_PERIODS, true ) ? $comparePeriodParam : '';

		$this->include = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$this->exclude = isset( $_GET['exclude'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['exclude'] ) ) : '';
		$this->tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$this->path    = isset( $_GET['path'] ) ? esc_url_raw( wp_unslash( (string) $_GET['path'] ) ) : '';

		// Sanitized member by member below, then checked against the rows
		// actually on the screen - a path being well formed is not the same as
		// the reader being allowed to see its numbers.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$compare = isset( $_GET['compare'] ) ? wp_unslash( $_GET['compare'] ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->compare = is_array( $compare )
			? array_slice(
				array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $compare ) ) ) ),
				0,
				ChartData::MAX_SERIES
			)
			: [];

		$this->rememberPreferences(
			$stored,
			$hasExplicitRange && DateRange::PRESET_CUSTOM !== $this->range->preset ? $this->range->preset : null,
			$hasExplicitGranularity ? $this->granularity->value : null,
			$hasExplicitComparePeriod ? $this->comparePeriod : null
		);
	}

	/**
	 * The state that should follow the reader from screen to screen.
	 *
	 * @return array<string,string>
	 */
	public function carried(): array {
		$carried = [ 'range' => $this->range->param ];

		foreach ( [
			'q'       => $this->include,
			'exclude' => $this->exclude,
			'tab'     => $this->tab,
		] as $key => $value ) {
			if ( '' !== $value ) {
				$carried[ $key ] = $value;
			}
		}

		if ( Granularity::Day !== $this->granularity ) {
			$carried['granularity'] = $this->granularity->value;
		}

		if ( '' !== $this->comparePeriod ) {
			$carried['compare_period'] = $this->comparePeriod;
		}

		return $carried;
	}

	/**
	 * The period this range is being compared against, or null when no
	 * comparison was asked for.
	 *
	 * "Same period last year" means the same span, shifted back exactly one
	 * calendar year - not a calendar-aware "same month last year" - which
	 * matches how {@see DateRange::previous()} already treats every preset:
	 * the span is what is preserved, not any meaning a preset name once had.
	 */
	public function comparisonRange(): ?DateRange {
		return match ( $this->comparePeriod ) {
			'previous' => $this->range->previous(),
			'year'     => $this->range->sameLastYear(),
			default    => null,
		};
	}

	/**
	 * A URL for this screen, with overrides applied.
	 *
	 * @param array<string,string|null> $overrides Parameters to add or remove.
	 * @param string|null               $screen    A different screen slug.
	 */
	public function url( array $overrides = [], ?string $screen = null ): string {
		$args = array_merge( $this->carried(), $overrides );

		// `path` names a row on one screen and means nothing on any other, so
		// it follows a link that stays put and is dropped by one that moves -
		// which is why it is not in carried(), the set that crosses screens.
		// Without this, every range, grouping and comparison button on a page
		// detail view quietly returned the reader to the list.
		//
		// array_key_exists, not isset: page.php passes an explicit null to
		// clear the path for its "back to Pages" link, and that null has to
		// count as having been asked for.
		if ( ( null === $screen || $screen === $this->screen )
			&& '' !== $this->path
			&& ! array_key_exists( 'path', $overrides ) ) {
			$args['path'] = $this->path;
		}

		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value ) {
				unset( $args[ $key ] );
			}
		}

		return add_query_arg( $args, menu_page_url( $screen ?? $this->screen, false ) );
	}

	/**
	 * Only the paths on screen may be compared.
	 *
	 * Without this check the parameter would answer "does this path exist on a
	 * site you can see the report for", one guess at a time.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows currently listed.
	 *
	 * @return string[]
	 */
	public function validatedComparePaths( array $rows ): array {
		if ( [] === $this->compare ) {
			return [];
		}

		$available = [];

		foreach ( $rows as $row ) {
			$path = (string) ( $row['path'] ?? '' );

			if ( '' !== $path ) {
				$available[ $path ] = true;
			}
		}

		$valid = [];

		foreach ( $this->compare as $path ) {
			if ( isset( $available[ $path ] ) ) {
				$valid[] = $path;
			}
		}

		return $valid;
	}

	/**
	 * "none" is what a link uses to explicitly switch comparison off - `""`
	 * itself cannot appear in a query string in a way that is distinguishable
	 * from the parameter being absent altogether, and an explicit "turn this
	 * off" has to be tellable apart from "nothing was asked".
	 *
	 * @param string $value Raw parameter value.
	 */
	private static function normalizeComparePeriod( string $value ): string {
		return 'none' === $value ? '' : $value;
	}

	/**
	 * How "All time" finds its start.
	 *
	 * Handed to {@see DateRange} as a closure rather than a date, so the query
	 * behind it only runs on a request that actually asked for all time - which
	 * is most requests avoided, this being constructed on every report screen.
	 */
	private static function earliestRecorded(): callable {
		return static fn (): ?string => Plugin::instance()->stats()->firstRecordedDate( get_current_blog_id() );
	}

	/**
	 * This user's stored range, granularity and comparison preference.
	 *
	 * @return array{range:string,granularity:string,compare_period:string}
	 */
	private static function storedPreferences(): array {
		$stored = get_user_meta( get_current_user_id(), self::USER_META, true );
		$stored = is_array( $stored ) ? $stored : [];

		$range = isset( $stored['range'] ) && isset( DateRange::presets()[ $stored['range'] ] )
			? (string) $stored['range']
			: '30d';

		$granularity = isset( $stored['granularity'] ) && null !== Granularity::tryFrom( (string) $stored['granularity'] )
			? (string) $stored['granularity']
			: Granularity::Day->value;

		$comparePeriod = isset( $stored['compare_period'] ) && in_array( $stored['compare_period'], self::COMPARE_PERIODS, true )
			? (string) $stored['compare_period']
			: '';

		return [
			'range'          => $range,
			'granularity'    => $granularity,
			'compare_period' => $comparePeriod,
		];
	}

	/**
	 * Write back whichever of the three preferences this request explicitly
	 * chose, leaving the rest exactly as they were stored.
	 *
	 * A request that fell back to the stored value for every one of the three
	 * writes nothing at all - merely loading a bookmarked or sidebar link must
	 * never be mistaken for a reader actively choosing something.
	 *
	 * @param array{range:string,granularity:string,compare_period:string} $stored          What was stored before this request.
	 * @param string|null                                                  $explicitRange   The chosen preset, if the request explicitly set one.
	 * @param string|null                                                  $explicitGranularity The chosen grain, if explicit.
	 * @param string|null                                                  $explicitCompare The chosen comparison, if explicit.
	 */
	private function rememberPreferences( array $stored, ?string $explicitRange, ?string $explicitGranularity, ?string $explicitCompare ): void {
		if ( null === $explicitRange && null === $explicitGranularity && null === $explicitCompare ) {
			return;
		}

		$userId = get_current_user_id();

		if ( $userId <= 0 ) {
			return;
		}

		$next = [
			'range'          => $explicitRange ?? $stored['range'],
			'granularity'    => $explicitGranularity ?? $stored['granularity'],
			'compare_period' => $explicitCompare ?? $stored['compare_period'],
		];

		if ( $next === $stored ) {
			return;
		}

		update_user_meta( $userId, self::USER_META, $next );
	}
}
