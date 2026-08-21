<?php
/**
 * A goal definition.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Goals;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Sessions\Session;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Something worth counting, and the rule that decides it happened.
 *
 * A goal converts at most once per visit. That is the whole reason conversion
 * rates here are conversions over sessions: somebody who reloads the thank-you
 * page three times converted once, and a definition that counted hits would
 * quietly reward broken forms.
 */
final class Goal {

	/** Longest handle the column holds. */
	public const MAX_HANDLE_LENGTH = 64;

	/** Longest name the column holds. */
	public const MAX_NAME_LENGTH = 191;

	/** Longest target the column holds. */
	public const MAX_TARGET_LENGTH = 500;

	/** The largest value decimal(14,2) can carry. */
	public const MAX_VALUE = 999999999999.99;

	/**
	 * What a handle may look like.
	 *
	 * Hyphens are allowed deliberately: the editor runs the field through
	 * `sanitize_key()`, which turns spaces into hyphens, so a pattern without
	 * them rejects handles the form itself produced.
	 */
	public const HANDLE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]*$/';

	public function __construct(
		public int $id = 0,
		public string $name = '',
		public string $handle = '',
		public string $type = 'url',
		public string $target = '',
		public float $value = 0.0,
		public bool $enabled = true,
		public int $sortOrder = 0
	) {
	}

	/**
	 * Rebuild from a database row.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public static function fromRow( array $row ): self {
		return new self(
			isset( $row['id'] ) ? (int) $row['id'] : 0,
			isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '',
			isset( $row['handle'] ) && is_scalar( $row['handle'] ) ? (string) $row['handle'] : '',
			isset( $row['type'] ) && is_scalar( $row['type'] ) ? (string) $row['type'] : 'url',
			isset( $row['target'] ) && is_scalar( $row['target'] ) ? (string) $row['target'] : '',
			isset( $row['value'] ) ? (float) $row['value'] : 0.0,
			! isset( $row['enabled'] ) || (bool) $row['enabled'],
			isset( $row['sortOrder'] ) ? (int) $row['sortOrder'] : 0
		);
	}

	/**
	 * The type as an enum.
	 */
	public function goalType(): GoalType {
		return GoalType::fromStored( $this->type );
	}

	/**
	 * Whether this goal is decided while the hits still exist.
	 */
	public function isLive(): bool {
		return $this->goalType()->isLive();
	}

	/**
	 * Whether one hit converts this goal.
	 *
	 * A goal whose answer belongs to the whole visit always says no here, and
	 * that is not a limitation to work around: a hit cannot know how long
	 * somebody stayed, and guessing from the hit in front of it would count the
	 * conversion on the first pageview of every visit.
	 *
	 * @param Hit $hit The hit.
	 */
	public function matchesHit( Hit $hit ): bool {
		if ( ! $this->enabled ) {
			return false;
		}

		return match ( $this->goalType() ) {
			GoalType::Url   => $hit->isPageview() && $this->matchesPath( $hit->path ),
			GoalType::Event => Hit::KIND_EVENT === $hit->kind && null !== $hit->eventName
				&& strtolower( trim( $this->target ) ) === strtolower( trim( $hit->eventName ) ),
			GoalType::Post  => $hit->isPageview() && null !== $hit->postId
				&& $hit->postId > 0 && $hit->postId === (int) $this->target,
			default         => false,
		};
	}

	/**
	 * Whether a finished visit converts this goal.
	 *
	 * @param Session $session The closed session.
	 */
	public function convertsAtClose( Session $session ): bool {
		if ( ! $this->enabled ) {
			return false;
		}

		return match ( $this->goalType() ) {
			GoalType::Duration => (int) $this->target > 0
				&& $session->durationMs() >= (int) $this->target * 1000,
			GoalType::Scroll   => (int) $this->target > 0
				&& $session->maxScroll >= (int) $this->target,
			default            => false,
		};
	}

	/**
	 * What is wrong with this definition, keyed by field.
	 *
	 * Checked before anything is written, because a goal that can never match
	 * is indistinguishable in the reports from a goal nobody converts - both
	 * sit at zero, and only one of them is telling the truth.
	 *
	 * @return array<string,string>
	 */
	public function validate(): array {
		$errors = [];

		$name = trim( $this->name );

		if ( '' === $name ) {
			$errors['name'] = __( 'Give the goal a name.', 'honest-analytics' );
		} elseif ( self::length( $name ) > self::MAX_NAME_LENGTH ) {
			$errors['name'] = sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Names are limited to %d characters.', 'honest-analytics' ),
				self::MAX_NAME_LENGTH
			);
		}

		if ( 1 !== preg_match( self::HANDLE_PATTERN, $this->handle ) ) {
			$errors['handle'] = __( 'Handles start with a letter and hold letters, numbers, hyphens and underscores.', 'honest-analytics' );
		} elseif ( self::length( $this->handle ) > self::MAX_HANDLE_LENGTH ) {
			$errors['handle'] = sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Handles are limited to %d characters.', 'honest-analytics' ),
				self::MAX_HANDLE_LENGTH
			);
		}

		if ( ! GoalType::isKnown( $this->type ) ) {
			$errors['type'] = __( 'Choose one of the goal types.', 'honest-analytics' );
		} else {
			$target = $this->validateTarget();

			if ( null !== $target ) {
				$errors['target'] = $target;
			}
		}

		if ( ! is_finite( $this->value ) || $this->value < 0.0 || $this->value > self::MAX_VALUE ) {
			$errors['value'] = __( 'A goal value is zero or more, and smaller than a trillion.', 'honest-analytics' );
		}

		return $errors;
	}

	/**
	 * What is wrong with the target, given the type.
	 */
	private function validateTarget(): ?string {
		$target = trim( $this->target );

		if ( '' === $target ) {
			return __( 'Say what the goal is looking for.', 'honest-analytics' );
		}

		if ( self::length( $target ) > self::MAX_TARGET_LENGTH ) {
			return sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'Targets are limited to %d characters.', 'honest-analytics' ),
				self::MAX_TARGET_LENGTH
			);
		}

		return match ( $this->goalType() ) {
			GoalType::Url      => str_starts_with( $target, '/' )
				? null
				: __( 'A path starts with a slash, like /thank-you.', 'honest-analytics' ),
			GoalType::Post     => ctype_digit( $target ) && (int) $target > 0
				? null
				: __( 'A post target is the numeric ID of a post or page.', 'honest-analytics' ),
			GoalType::Duration => ctype_digit( $target ) && (int) $target > 0
				? null
				: __( 'A time target is a whole number of seconds.', 'honest-analytics' ),
			GoalType::Scroll   => in_array( (int) $target, GoalType::SCROLL_TARGETS, true )
				? null
				: __( 'Scroll depth is recorded in quarters, so the target is 25, 50, 75 or 100.', 'honest-analytics' ),
			GoalType::Event    => null,
		};
	}

	/**
	 * Characters in a string, counted the way the column counts them.
	 *
	 * The columns are varchar, so their limits are characters rather than
	 * bytes; measuring in bytes would refuse a perfectly ordinary name written
	 * in a language that does not fit in one byte per letter.
	 *
	 * @param string $value Value.
	 */
	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Whether a path converts this goal.
	 *
	 * @param string $path The path from the hit.
	 */
	private function matchesPath( string $path ): bool {
		$target = self::normalisePath( $this->target );
		$path   = self::normalisePath( $path );

		if ( '' === $target || '' === $path ) {
			return false;
		}

		if ( ! str_contains( $target, '*' ) ) {
			return $target === $path;
		}

		$pattern = '#^' . str_replace( '\*', '.*', preg_quote( $target, '#' ) ) . '$#i';

		return 1 === preg_match( $pattern, $path );
	}

	/**
	 * A path reduced to the part a goal is allowed to care about.
	 *
	 * The query string goes: campaign parameters and session junk are on the
	 * end of half the thank-you page URLs on the web, and a goal that had to
	 * match them exactly would convert for some visitors and not others.
	 *
	 * @param string $path Raw path.
	 */
	private static function normalisePath( string $path ): string {
		$path = trim( $path );

		foreach ( [ '?', '#' ] as $cut ) {
			$at = strpos( $path, $cut );

			if ( false !== $at ) {
				$path = substr( $path, 0, $at );
			}
		}

		if ( '' === $path ) {
			return '';
		}

		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
