<?php
/**
 * The extra rollup rows, in a build that has no reports for them.
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
 * The tables themselves still exist - every build shares one schema, and that is
 * what makes moving between packages lose nothing - so this writes no rows
 * rather than failing to find somewhere to put them. A site that gains those
 * reports starts filling the tables from that moment; history it never
 * collected is simply absent, which is the honest answer.
 */
final class NoExtraRollups implements ExtraRollupsInterface {

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
