<?php
/**
 * What a unique count is scoped to.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Uniques;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The address of one sketch.
 *
 * Readers build a scope from the row they are looking at, which is why the hour
 * is part of it: once a day has been compacted its rows carry hour -1, and a
 * reader asking for real hours would find nothing.
 */
final class UniqueScope {

	public const KIND_PAGE    = 'p';
	public const KIND_SESSION = 's';

	/**
	 * Everybody who viewed any page on a site that day.
	 *
	 * A kind of its own rather than KIND_PAGE with no dimension id, because
	 * `key()` renders a null id as `0` and would then be indistinguishable from
	 * a page whose dimension id happened to be zero. Nothing produces one today
	 * - dimension ids are auto-increment and start at one - but a scope key is
	 * stored, so a collision would be permanent and silent.
	 */
	public const KIND_SITE = 'a';

	/** The hour a compacted daily row carries. */
	public const HOUR_DAILY = -1;

	public function __construct(
		public readonly string $kind,
		public readonly int $siteId,
		public readonly string $date,
		public readonly int $hour,
		public readonly ?int $dimId = null
	) {
	}

	/**
	 * A stable string form.
	 */
	public function key(): string {
		return implode( ':', [ $this->kind, $this->siteId, $this->date, $this->hour, $this->dimId ?? 0 ] );
	}
}
