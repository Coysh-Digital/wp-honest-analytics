<?php
/**
 * The Pro half of a rollup write, in a build that has no Pro reports.
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
 * No campaign, event, goal or crawler rows, because nothing would read them.
 *
 * The tables themselves still exist - both editions share a schema, and that is
 * what makes moving between them lose nothing - so this writes no rows rather
 * than failing to find somewhere to put them. A site that upgrades starts
 * filling those tables from that moment; history it never collected is simply
 * absent, which is the honest answer and the one documented in editions.md.
 */
final class NoProRollups implements ProRollupWriterInterface {

	/**
	 * @param Session         $session  Session, ignored.
	 * @param string          $date     Date, ignored.
	 * @param DimensionCapper $capper   Capper, ignored.
	 * @param DateTimeZone    $timezone Timezone, ignored.
	 */
	public function writeSession( Session $session, string $date, DimensionCapper $capper, DateTimeZone $timezone ): void {
	}

	/**
	 * @param InteractionBuckets $interactions Interactions, ignored.
	 * @param DimensionCapper    $capper       Capper, ignored.
	 */
	public function writeInteractions( InteractionBuckets $interactions, DimensionCapper $capper ): void {
	}
}
