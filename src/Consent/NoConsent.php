<?php
/**
 * Consented identity in a build with no consented tier.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nobody has agreed to anything durable, so there is no durable id.
 *
 * The free edition is cookieless by default and carries no consented tier at
 * all: no cookie to read, no log to write, no endpoint to record a decision.
 * Answering null here is not a refusal being simulated - it is the true answer
 * for a build where the question cannot be asked.
 *
 * Everything else about a hit is unchanged. The visitor hash the free edition
 * does use comes from Identity, is derived from a salt that rotates every
 * twenty-four hours, and has nothing to do with this.
 */
final class NoConsent implements ConsentResolverInterface {

	public function isAvailable(): bool {
		return false;
	}

	/**
	 * @param int                  $siteId  Site ID, ignored.
	 * @param array<string,string> $headers Request headers, ignored.
	 * @param array<string,string> $cookies Request cookies, ignored.
	 */
	public function visitorId( int $siteId, array $headers = [], array $cookies = [] ): ?string {
		return null;
	}
}
