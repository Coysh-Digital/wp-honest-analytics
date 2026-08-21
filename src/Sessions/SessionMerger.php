<?php
/**
 * Folding a batch's activity into a session.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The merge rules, shared by both session stores.
 *
 * Kept out of the stores so the two implementations cannot drift: whichever one
 * a site ends up using, a replayed batch has to leave the session in exactly
 * the state the first attempt did.
 */
final class SessionMerger {

	/**
	 * Apply a delta to a session, creating or restarting it as needed.
	 *
	 * @param Session|null $existing      Session already held, if any.
	 * @param SessionDelta $delta         Activity from this batch.
	 * @param string       $batchId       Batch identifier.
	 * @param int          $sessionWindow Idle seconds allowed.
	 */
	public static function apply( ?Session $existing, SessionDelta $delta, string $batchId, int $sessionWindow ): Session {
		// This batch has already been applied. Absorbing the replay silently is
		// the whole reason the batch id is stored on the session.
		if ( null !== $existing && $existing->lastBatch === $batchId ) {
			return $existing;
		}

		if ( null === $existing || $existing->hasExpired( $delta->firstSeen, $sessionWindow ) ) {
			return self::start( $delta, $batchId );
		}

		return self::merge( $existing, $delta, $batchId );
	}

	/**
	 * Begin a fresh visit.
	 *
	 * @param SessionDelta $delta   Activity.
	 * @param string       $batchId Batch identifier.
	 */
	private static function start( SessionDelta $delta, string $batchId ): Session {
		return new Session(
			siteId: $delta->siteId,
			sessionKey: $delta->sessionKey,
			visitorHash: $delta->visitorHash,
			startedAt: $delta->firstSeen,
			lastSeenAt: $delta->lastSeen,
			pageviews: $delta->views,
			entryPath: $delta->entryPath,
			lastPath: $delta->lastPath,
			referrer: $delta->referrer,
			userAgent: $delta->userAgent,
			lastBatch: $batchId,
			closedByBatch: null,
			campaigns: $delta->campaigns,
			countryCode: $delta->countryCode,
			region: $delta->region,
			goals: $delta->goals,
			maxScroll: $delta->maxScroll,
			visitorId: $delta->visitorId,
			userId: $delta->userId
		);
	}

	/**
	 * Continue a visit already in progress.
	 *
	 * @param Session      $session Existing session.
	 * @param SessionDelta $delta   Activity.
	 * @param string       $batchId Batch identifier.
	 */
	private static function merge( Session $session, SessionDelta $delta, string $batchId ): Session {
		$session->pageviews += $delta->views;
		$session->startedAt  = min( $session->startedAt, $delta->firstSeen );

		if ( $delta->lastSeen >= $session->lastSeenAt ) {
			$session->lastSeenAt = $delta->lastSeen;
			$session->lastPath   = $delta->lastPath;
		}

		if ( '' === $session->userAgent && '' !== $delta->userAgent ) {
			$session->userAgent = $delta->userAgent;
		}

		// The acquisition referrer belongs to the visit, not to the page. It is
		// set when the visit starts and never replaced, because the second page
		// of a visit has this site as its referrer and that is not where they
		// came from.
		if ( '' === $session->referrer && '' !== $delta->referrer ) {
			$session->referrer = $delta->referrer;
		}

		if ( '' === $session->countryCode && '' !== $delta->countryCode ) {
			$session->countryCode = $delta->countryCode;
			$session->region      = $delta->region;
		}

		if ( null === $session->visitorId && null !== $delta->visitorId ) {
			$session->visitorId = $delta->visitorId;
			$session->userId    = $delta->userId;
		}

		foreach ( $delta->campaigns as $touch ) {
			if ( ! in_array( $touch, $session->campaigns, true ) ) {
				$session->campaigns[] = $touch;
			}
		}

		// Union rather than append: this is what makes a goal convert once per
		// session however many times somebody reloads the thank-you page.
		foreach ( $delta->goals as $handle ) {
			if ( ! in_array( $handle, $session->goals, true ) ) {
				$session->goals[] = $handle;
			}
		}

		$session->maxScroll = max( $session->maxScroll, $delta->maxScroll );
		$session->lastBatch = $batchId;

		return $session;
	}
}
