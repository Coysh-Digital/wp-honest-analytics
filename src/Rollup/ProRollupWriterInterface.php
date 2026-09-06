<?php
/**
 * The Pro half of a rollup write.
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
 * The rows only the paid reports read, written beside the ones every build does.
 *
 * DbRollupSink holds one of these and calls it while flushing a batch, inside
 * the transaction that makes a day whole. A build with no Pro reports writes
 * no Pro rows and the rest of the flush is unchanged.
 *
 * Every type on this contract survives the strip - Session, DimensionCapper,
 * InteractionBuckets and DateTimeZone are all shared. That is what makes this
 * a seam rather than the same problem one file further along.
 */
interface ProRollupWriterInterface {

	/**
	 * Write what a finished session contributes to the Pro reports.
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
