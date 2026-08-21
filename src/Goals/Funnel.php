<?php
/**
 * A funnel definition.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Goals;

use HonestAnalytics\Sessions\Session;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Goals put in order, measured within a single visit.
 *
 * A funnel asks a stricter question than the Goals report does. Somebody who
 * reached the contact page without reading a post converted the enquiry goal -
 * that is true, and it is counted - but they did not walk this funnel, and
 * crediting them would turn every drop-off figure into a guess. The walk is
 * therefore ordered, and it is confined to one visit: joining up two visits a
 * week apart would need a durable identifier, which the anonymous layer of this
 * plugin does not have and is not going to grow.
 */
final class Funnel {

	/** Longest funnel anybody has a use for, and a bound on the row writes. */
	public const MAX_STEPS = 10;

	/**
	 * @param int      $id        Row id.
	 * @param string   $name      Display name.
	 * @param string   $handle    Stable handle.
	 * @param bool     $enabled   Whether it is measured.
	 * @param int      $sortOrder Display order.
	 * @param string[] $steps     Goal handles, in order.
	 */
	public function __construct(
		public int $id = 0,
		public string $name = '',
		public string $handle = '',
		public bool $enabled = true,
		public int $sortOrder = 0,
		public array $steps = []
	) {
	}

	/**
	 * How many steps a finished visit walked.
	 *
	 * Returns the position reached, so a row can be written at every position
	 * up to it and the report reads "how many got at least this far".
	 *
	 * @param Session            $session       The closed session.
	 * @param array<string,Goal> $goalsByHandle Live goals, keyed by handle.
	 */
	public function reachedStep( Session $session, array $goalsByHandle ): int {
		$converted = array_values( array_filter( $session->goals, 'is_string' ) );
		$cursor    = 0;
		$reached   = 0;

		foreach ( $this->steps as $handle ) {
			$goal = $goalsByHandle[ $handle ] ?? null;

			// A step naming a goal that no longer exists stops the walk.
			// Skipping it would quietly measure a shorter funnel and report a
			// completion rate nobody configured.
			if ( null === $goal ) {
				return $reached;
			}

			// A whole-visit goal has no position in the ordered list - duration
			// is not something that happened between two pageviews - so it acts
			// as a gate: it has to hold, but it does not move the cursor.
			if ( ! $goal->isLive() ) {
				if ( ! $goal->convertsAtClose( $session ) ) {
					return $reached;
				}

				++$reached;

				continue;
			}

			$at = self::indexOf( $converted, $handle, $cursor );

			if ( null === $at ) {
				return $reached;
			}

			++$reached;

			$cursor = $at + 1;
		}

		return $reached;
	}

	/**
	 * What is wrong with this definition, keyed by field.
	 *
	 * @return array<string,string>
	 */
	public function validate(): array {
		$errors = [];

		if ( '' === trim( $this->name ) ) {
			$errors['name'] = __( 'Give the funnel a name.', 'honest-analytics' );
		}

		if ( 1 !== preg_match( Goal::HANDLE_PATTERN, $this->handle ) ) {
			$errors['handle'] = __( 'Handles start with a letter and hold letters, numbers, hyphens and underscores.', 'honest-analytics' );
		}

		$steps = array_values( array_filter( $this->steps, 'is_string' ) );

		if ( count( $steps ) < 2 ) {
			$errors['steps'] = __( 'A funnel needs at least two steps - with one there is nothing to drop off between.', 'honest-analytics' );
		} elseif ( count( $steps ) > self::MAX_STEPS ) {
			$errors['steps'] = sprintf(
				/* translators: %d: maximum number of steps. */
				__( 'A funnel holds at most %d steps.', 'honest-analytics' ),
				self::MAX_STEPS
			);
		} elseif ( count( array_unique( $steps ) ) !== count( $steps ) ) {
			// The second occurrence is unreachable: the first conversion
			// consumes the match and the cursor has already moved past it.
			$errors['steps'] = __( 'Each goal appears once - a repeated step could never be reached.', 'honest-analytics' );
		} else {
			foreach ( $steps as $handle ) {
				if ( 1 !== preg_match( Goal::HANDLE_PATTERN, $handle ) ) {
					$errors['steps'] = __( 'Every step names a goal by its handle.', 'honest-analytics' );

					break;
				}
			}
		}

		return $errors;
	}

	/**
	 * Where a handle appears in the ordered conversions, at or after a point.
	 *
	 * @param string[] $converted Handles in the order they converted.
	 * @param string   $handle    Handle to find.
	 * @param int      $from      Index to start at.
	 */
	private static function indexOf( array $converted, string $handle, int $from ): ?int {
		$count = count( $converted );

		for ( $index = $from; $index < $count; $index++ ) {
			if ( $converted[ $index ] === $handle ) {
				return $index;
			}
		}

		return null;
	}
}
