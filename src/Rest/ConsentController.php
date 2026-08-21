<?php
/**
 * The consent endpoint.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

use HonestAnalytics\Capture\ShutdownRunner;
use HonestAnalytics\Consent\ConsentCookie;
use HonestAnalytics\Consent\ConsentMethod;
use HonestAnalytics\Consent\ConsentService;
use HonestAnalytics\Consent\ConsentState;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Plugin;
use HonestAnalytics\Privacy\PrivacyService;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\ClientIp;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a yes or a no is recorded.
 *
 * Three things happen here and the order is the point:
 *
 * 1. A browser privacy signal is checked first and cannot be overridden. A
 *    visitor whose browser sends GPC gets a refusal recorded no matter what the
 *    banner posted - including when the banner posted "granted", which is what
 *    a consent platform that has not noticed the signal will do.
 * 2. The device is brought into line: a grant sets the cookie, anything else
 *    removes it.
 * 3. The decision is written to the log, which is the evidence that the
 *    processing either side of it was lawful.
 *
 * A withdrawal goes one step further and deletes the journeys already held for
 * that identifier. Stopping is not the same as forgetting, and somebody who
 * withdraws is asking for both.
 */
final class ConsentController {

	/** A banner cannot legitimately change its mind twenty times a minute. */
	private const RATE_LIMIT = 20;

	public function __construct(
		private Settings $settings,
		private ConsentService $consent,
		private IdentityService $identity,
		private ClientIp $clientIp,
		private RateLimit $limits
	) {
	}

	/**
	 * Build one from the container.
	 */
	public static function make(): self {
		$plugin = Plugin::instance();

		return new self(
			$plugin->settings(),
			$plugin->consent(),
			$plugin->identity(),
			$plugin->clientIp(),
			new RateLimit( StoreFactory::keyValue() )
		);
	}

	/**
	 * The REST route callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest( \WP_REST_Request $request ): \WP_REST_Response {
		$this->run( CollectController::bodyParams( $request ), CollectController::headers( $request ) );

		return NoContent::response();
	}

	/**
	 * Handle a decision, never throwing.
	 *
	 * @param array<string,string> $params  Posted fields.
	 * @param array<string,string> $headers Request headers, lower-cased.
	 */
	public function run( array $params, array $headers ): void {
		ShutdownRunner::markCollectRequest();

		try {
			$this->record( $params, $headers );
		} catch ( \Throwable $e ) {
			Log::warning( 'A consent decision could not be recorded: ' . $e->getMessage() );
		}
	}

	/**
	 * The work.
	 *
	 * @param array<string,string> $params  Posted fields.
	 * @param array<string,string> $headers Request headers, lower-cased.
	 */
	private function record( array $params, array $headers ): void {
		if ( ! $this->consent->isAvailable() ) {
			return;
		}

		$siteId = get_current_blog_id();

		// Transient, exactly as in the capture path: hashed and discarded.
		$ip          = $this->clientIp->resolve();
		$visitorHash = $this->identity->visitorHash( $ip, $headers['user-agent'] ?? '', $siteId );
		unset( $ip );

		if ( $this->limits->exceeded( 'consent', $visitorHash, self::RATE_LIMIT ) ) {
			return;
		}

		$signalled = $this->hasPrivacySignal( $headers );
		$requested = strtolower( trim( (string) ( $params['state'] ?? '' ) ) );

		if ( ! $signalled && ! in_array( $requested, [ 'granted', 'denied', 'withdrawn' ], true ) ) {
			return;
		}

		$method = $signalled
			? ConsentMethod::PrivacySignal
			: ConsentMethod::fromStored( strtolower( trim( (string) ( $params['method'] ?? 'js' ) ) ) );

		$state = ( ! $signalled && 'granted' === $requested ) ? ConsentState::Granted : ConsentState::Denied;

		if ( ConsentState::Granted === $state ) {
			$this->grant( $siteId, $method, $visitorHash );

			return;
		}

		$this->refuse( $siteId, $method, $visitorHash, 'withdrawn' === $requested );
	}

	/**
	 * Record a grant and give the visitor an identifier.
	 *
	 * An identifier they already have is kept: re-announcing consent on every
	 * page is what a consent platform does, and minting a new identifier each
	 * time would turn one consented visitor into a hundred.
	 *
	 * @param int           $siteId      Site ID.
	 * @param ConsentMethod $method      How it was expressed.
	 * @param string        $visitorHash Rotating hash, for the log.
	 */
	private function grant( int $siteId, ConsentMethod $method, string $visitorHash ): void {
		$existing  = ConsentCookie::read( $this->settings );
		$visitorId = $existing ?? ConsentService::newVisitorId();

		ConsentCookie::set( $this->settings, $visitorId );

		if ( null !== $existing ) {
			// Already consented, already logged. Refreshing the cookie is not a
			// new decision and does not deserve a new row.
			return;
		}

		$this->consent->log( $siteId, ConsentState::Granted, $method, $visitorId, $visitorHash );
	}

	/**
	 * Record a refusal, and forget what was held if this is a withdrawal.
	 *
	 * @param int           $siteId      Site ID.
	 * @param ConsentMethod $method      How it was expressed.
	 * @param string        $visitorHash Rotating hash, for the log.
	 * @param bool          $withdrawn   Whether this reverses an earlier grant.
	 */
	private function refuse( int $siteId, ConsentMethod $method, string $visitorHash, bool $withdrawn ): void {
		$visitorId = ConsentCookie::read( $this->settings );

		ConsentCookie::clear( $this->settings );

		// The log row is written before the erasure, and keeps the identifier.
		// Without it there would be no record of who asked to be forgotten, and
		// the drain would have nothing to check the spool against when it next
		// runs - so hits captured moments before the withdrawal would be
		// written back in.
		$this->consent->log( $siteId, ConsentState::Denied, $method, $visitorId, $visitorHash );

		if ( ! $withdrawn || null === $visitorId ) {
			return;
		}

		( new PrivacyService( $this->settings ) )->erase( $visitorId );
	}

	/**
	 * Whether the browser has signalled a refusal.
	 *
	 * @param array<string,string> $headers Request headers.
	 */
	private function hasPrivacySignal( array $headers ): bool {
		if ( $this->settings->honourGpc && '1' === ( $headers['sec-gpc'] ?? '' ) ) {
			return true;
		}

		return $this->settings->honourDnt && '1' === ( $headers['dnt'] ?? '' );
	}
}
