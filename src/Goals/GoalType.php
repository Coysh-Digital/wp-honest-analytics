<?php
/**
 * Goal types.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Goals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of thing a goal can be.
 *
 * The split that matters is not what a type measures but *when* it can be
 * decided. A path, an event and a post are properties of one hit, so they are
 * matched during the drain while the hits still exist. Duration and scroll
 * depth are properties of a whole visit, so they cannot be answered until the
 * session closes - and a driver that tries to decide them per hit reports every
 * one of them as zero.
 */
enum GoalType: string {

	case Url      = 'url';
	case Event    = 'event';
	case Post     = 'post';
	case Duration = 'duration';
	case Scroll   = 'scroll';

	/**
	 * The scroll depths the tracker can actually report.
	 *
	 * Depth arrives in quarters, so a goal set at 80% would never convert. It
	 * is refused at validation rather than left to look broken later.
	 */
	public const SCROLL_TARGETS = [ 25, 50, 75, 100 ];

	/**
	 * The type a stored value names, falling back to the commonest.
	 *
	 * @param string $value Stored value.
	 */
	public static function fromStored( string $value ): self {
		return self::tryFrom( strtolower( trim( $value ) ) ) ?? self::Url;
	}

	/**
	 * Whether a stored value names a real type.
	 *
	 * @param string $value Stored value.
	 */
	public static function isKnown( string $value ): bool {
		return null !== self::tryFrom( strtolower( trim( $value ) ) );
	}

	/**
	 * Whether this type is decided from a single hit.
	 */
	public function isLive(): bool {
		return match ( $this ) {
			self::Url, self::Event, self::Post => true,
			self::Duration, self::Scroll       => false,
		};
	}

	/**
	 * A human label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Url      => __( 'Page visited', 'honest-analytics' ),
			self::Event    => __( 'Event fired', 'honest-analytics' ),
			self::Post     => __( 'Post or page read', 'honest-analytics' ),
			self::Duration => __( 'Time on site', 'honest-analytics' ),
			self::Scroll   => __( 'Scroll depth', 'honest-analytics' ),
		};
	}

	/**
	 * What the target field holds, for the editor.
	 */
	public function targetLabel(): string {
		return match ( $this ) {
			self::Url      => __( 'Path', 'honest-analytics' ),
			self::Event    => __( 'Event name', 'honest-analytics' ),
			self::Post     => __( 'Post ID', 'honest-analytics' ),
			self::Duration => __( 'Seconds', 'honest-analytics' ),
			self::Scroll   => __( 'Percent', 'honest-analytics' ),
		};
	}

	/**
	 * A one-line explanation of what will be accepted.
	 */
	public function targetHint(): string {
		return match ( $this ) {
			self::Url      => __( 'A path beginning with a slash. A trailing * matches everything below it, and the query string is ignored.', 'honest-analytics' ),
			self::Event    => __( 'The name passed to the event, matched exactly.', 'honest-analytics' ),
			self::Post     => __( 'The numeric ID of the post or page.', 'honest-analytics' ),
			self::Duration => __( 'Seconds spent on the site before the visit went idle.', 'honest-analytics' ),
			self::Scroll   => __( 'One of 25, 50, 75 or 100 - depth is recorded in quarters.', 'honest-analytics' ),
		};
	}

	/**
	 * An example value, shown as a placeholder.
	 */
	public function placeholder(): string {
		return match ( $this ) {
			self::Url      => '/thank-you',
			self::Event    => 'newsletter-signup',
			self::Post     => '42',
			self::Duration => '60',
			self::Scroll   => '75',
		};
	}
}
