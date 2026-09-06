<?php
/**
 * Consented identity contract.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this visit carries a durable identity the visitor agreed to.
 *
 * The seam between the capture path and the consented tier. CaptureService and
 * CollectController each hold one and call it on every hit, so it has to be
 * satisfiable in a build that has no consented tier at all.
 *
 * One method where the capture path used two. Both call sites did the same
 * thing - resolve a state, ask whether it was granted, then fetch the id - and
 * both happened to get that two-step protocol identically right. Asking the
 * question once is what keeps ConsentState off this contract, and the enum does
 * not survive the strip.
 */
interface ConsentResolverInterface {

	/**
	 * Whether the consented tier is switched on and available in this build.
	 */
	public function isAvailable(): bool;

	/**
	 * The durable visitor id for this request, if there is one.
	 *
	 * Null whenever consent was not affirmatively granted, which includes
	 * every request in a build that has no consented tier.
	 *
	 * @param int                  $siteId  Site ID.
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,string> $cookies Request cookies.
	 */
	public function visitorId( int $siteId, array $headers = [], array $cookies = [] ): ?string;
}
