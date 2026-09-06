<?php
/**
 * The rollup rows beyond the core ones.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use DateTimeZone;
use HonestAnalytics\Dimensions\DimensionCapper;
use HonestAnalytics\Sessions\Session;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rows only some reports read, written beside the ones every build writes.
 *
 * DbRollupSink holds one of these and calls it while flushing a batch, inside
 * the transaction that makes a day whole. A build with no reports for these
 * rows writes none of them, and the rest of the flush is unchanged.
 *
 * Every type on this contract survives the strip - Session, DimensionCapper,
 * InteractionBuckets and DateTimeZone are all shared. That is what makes this
 * a seam rather than the same problem one file further along.
 */
interface ExtraRollupsInterface {

	/**
	 * Write what a finished session contributes beyond the core rollups.
	 *
	 * @param Session         $session  Session.
	 * @param string          $date     Local date the session started.
	 * @param DimensionCapper $capper   Dimension capper.
	 * @param DateTimeZone    $timezone Site timezone.
	 */
	public function writeSession( Session $session, string $date, DimensionCapper $capper, DateTimeZone $timezone ): void;

	/**
	 * Write everything in a batch that was not a pageview.
	 *
	 * @param InteractionBuckets $interactions Events, scroll, outbound, searches, crawlers.
	 * @param DimensionCapper    $capper       Dimension capper.
	 */
	public function writeInteractions( InteractionBuckets $interactions, DimensionCapper $capper ): void;
}
