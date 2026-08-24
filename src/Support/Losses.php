<?php
/**
 * A durable count of the things that mean a view was not counted.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The handful of events that mean data was lost, counted where somebody can
 * see them.
 *
 * `Log::write()` is gated on `WP_DEBUG`, which is off on every production site
 * that has not been asked to turn it on. That is the right default for a debug
 * channel and the wrong one for these: a hit dropped at the spool ceiling, a
 * queued hit refused because the table is full, a batch set aside after three
 * failures. Each of those is a number that will never appear in a report, and
 * on the sites where it happens most nobody is reading the debug log.
 *
 * So they are counted here as well, in one autoloaded option, and the Settings
 * screen reads it. Deliberately a count and a timestamp rather than a log: the
 * question this answers is "has this site been losing views, and how recently",
 * which is one row of numbers. What was in the hits that were dropped is gone,
 * and no amount of recording here would bring it back.
 *
 * Best effort by design. A failed write here must never be the reason a request
 * fails, because every one of these is already the unhappy path.
 */
final class Losses {

	/**
	 * Autoloaded, because it is read on the Settings screen and written from
	 * paths that are already in trouble - a second uncached query is the last
	 * thing either of them needs.
	 */
	private const OPTION = 'honest_analytics_losses';

	/** A hit too large for one spool line. */
	public const OVERSIZED = 'oversized';

	/** The write queue or the spool file is at its ceiling. */
	public const FULL = 'full';

	/** A batch set aside after repeated failures. */
	public const QUARANTINED = 'quarantined';

	/** A claimed spool file that could not be opened or read. */
	public const UNREADABLE = 'unreadable';

	/**
	 * Note that something was lost.
	 *
	 * @param string $kind  One of the constants above.
	 * @param int    $count How many.
	 */
	public static function record( string $kind, int $count = 1 ): void {
		if ( $count < 1 || ! in_array( $kind, self::kinds(), true ) ) {
			return;
		}

		$stored = self::all();

		$stored[ $kind ] = ( $stored[ $kind ] ?? 0 ) + $count;
		$stored['at']    = time();

		update_option( self::OPTION, $stored, true );
	}

	/**
	 * Everything counted so far.
	 *
	 * @return array<string,int>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$out = [];

		foreach ( $stored as $key => $value ) {
			if ( is_string( $key ) && is_numeric( $value ) ) {
				$out[ $key ] = (int) $value;
			}
		}

		return $out;
	}

	/**
	 * How many views this site is known to have lost.
	 */
	public static function total(): int {
		$total = 0;

		foreach ( self::kinds() as $kind ) {
			$total += self::all()[ $kind ] ?? 0;
		}

		return $total;
	}

	/**
	 * When the last one happened, or null if none has.
	 */
	public static function lastAt(): ?int {
		$at = self::all()['at'] ?? 0;

		return $at > 0 ? $at : null;
	}

	/**
	 * Start again.
	 *
	 * Offered because the count is cumulative and the fault is usually fixed by
	 * changing something - a spool ceiling, a disk, a host. Somebody who has
	 * done that needs to be able to see whether it worked.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The kinds that are counted.
	 *
	 * @return string[]
	 */
	private static function kinds(): array {
		return [ self::OVERSIZED, self::FULL, self::QUARANTINED, self::UNREADABLE ];
	}
}
