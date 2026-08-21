<?php
/**
 * Deciding which goals converted.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Goals\Goal;
use HonestAnalytics\Goals\GoalsService;
use HonestAnalytics\Sessions\Session;
use HonestAnalytics\Sessions\SessionDelta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches goals while the hits still exist.
 *
 * This has to happen during the drain and nowhere else: by the time a session
 * closes the hits have been folded into counters, and a goal targeting a path
 * or an event name has nothing left to match against. A driver that skips this
 * step reports every goal as zero, forever, with no error anywhere.
 */
final class GoalMatcher {

	private GoalsService $goals;

	/**
	 * @param GoalsService $goals Goals service.
	 */
	public function __construct( GoalsService $goals ) {
		$this->goals = $goals;
	}

	/**
	 * Match live goals across a batch of hits.
	 *
	 * @param Hit[]                      $hits   Hits in the batch.
	 * @param array<string,SessionDelta> $deltas Deltas, keyed by site and session.
	 */
	public function matchBatch( array $hits, array $deltas ): void {
		if ( ! Edition::isPro() ) {
			return;
		}

		$live = $this->goals->live();

		if ( [] === $live || [] === $hits ) {
			return;
		}

		// Spool order is nearly time order, and "nearly" is how a funnel ends up
		// occasionally reporting somebody reached step three before step two.
		usort( $hits, static fn ( Hit $a, Hit $b ): int => $a->timestamp <=> $b->timestamp );

		foreach ( $hits as $hit ) {
			$key = SessionDelta::key( $hit->siteId, $hit->sessionKey );

			if ( ! isset( $deltas[ $key ] ) ) {
				continue;
			}

			foreach ( $live as $goal ) {
				if ( $goal->matchesHit( $hit ) ) {
					$deltas[ $key ]->matchGoal( $goal->handle );
				}
			}
		}
	}

	/**
	 * The goals a finished session converted.
	 *
	 * @param Session $session The session.
	 *
	 * @return Goal[]
	 */
	public function conversions( Session $session ): array {
		if ( ! Edition::isPro() ) {
			return [];
		}

		$byHandle = $this->goals->byHandle();
		$out      = [];

		foreach ( $session->goals as $handle ) {
			$goal = $byHandle[ $handle ] ?? null;

			// A goal deleted between converting and the session closing is
			// dropped rather than counted against a name that no longer exists.
			if ( null !== $goal && $goal->enabled ) {
				$out[ $goal->handle ] = $goal;
			}
		}

		foreach ( $this->goals->enabled() as $goal ) {
			if ( ! $goal->isLive() && $goal->convertsAtClose( $session ) ) {
				$out[ $goal->handle ] = $goal;
			}
		}

		return array_values( $out );
	}
}
