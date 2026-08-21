<?php
/**
 * Server-side capture.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Bots\BotFilter;
use HonestAnalytics\Channels\Campaign;
use HonestAnalytics\Consent\ConsentService;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Geo\GeoService;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\ClientIp;
use HonestAnalytics\Support\Url;
use HonestAnalytics\Write\WriterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a served page into a hit.
 *
 * Everything here runs after the response has been flushed to the visitor, so
 * none of it is on the path they wait for.
 */
final class CaptureService {

	/**
	 * The visitor hash recorded for a crawler.
	 *
	 * Deliberately not hexadecimal, so it can never be mistaken for a real one
	 * by code that validates the format.
	 */
	public const CRAWLER_HASH = 'crawler000000000';

	public function __construct(
		private Settings $settings,
		private Trackability $trackability,
		private PathNormalizer $paths,
		private IdentityService $identity,
		private NonceRegistry $nonces,
		private ScriptInjector $injector,
		private BotFilter $bots,
		private GeoService $geo,
		private ConsentService $consent,
		private WriterInterface $writer,
		private ClientIp $clientIp
	) {
	}

	/**
	 * Capture a served page.
	 *
	 * @param RequestContext $context  Request snapshot.
	 * @param ResponseFacts  $response Response facts.
	 *
	 * @return Hit|null The hit written, if any.
	 */
	public function capture( RequestContext $context, ResponseFacts $response ): ?Hit {
		if ( ! $this->settings->usesServerCapture() ) {
			return null;
		}

		if ( ! $this->trackability->isTrackable( $context, $response ) ) {
			return null;
		}

		$siteId = get_current_blog_id();
		$nonce  = $this->injector->pendingNonce();

		// Nobody rendered this page in PHP and the tracker still went out with
		// it, which means it came from a cache: stand aside and let the beacon
		// count it, or the same view is counted twice on every cache miss and
		// zero times on every hit.
		if ( null === $nonce && ! $this->injector->templateRendered() && $this->isCachedDelivery() ) {
			return null;
		}

		$hit = $this->buildHit( $context, $siteId );

		/**
		 * Filters a hit before it is written.
		 *
		 * Return null to drop it.
		 *
		 * @param Hit|null       $hit     The hit.
		 * @param RequestContext $context Request snapshot.
		 */
		$hit = apply_filters( 'honest_analytics_before_track', $hit, $context );

		if ( ! $hit instanceof Hit ) {
			return null;
		}

		// Recorded before the write, deliberately. The tracker is deferred, so
		// its beacon can arrive while this method is still running; recording
		// afterwards would let that beacon find no nonce and count a second
		// view. The trade is that a failed write leaves a nonce claiming a view
		// that was never recorded - one view lost rather than one invented,
		// which is the right way round.
		if ( null !== $nonce ) {
			$this->nonces->record( $nonce, $hit->visitorHash );
		}

		$this->writer->write( $hit );

		return $hit;
	}

	/**
	 * Record a crawler request.
	 *
	 * No visitor hash, no session, no address - a name and a count. That is the
	 * whole record, and it is what lets the Crawlers screen answer "my server
	 * log says 4,000 requests and this says 400".
	 *
	 * @param RequestContext $context  Request snapshot.
	 * @param ResponseFacts  $response Response facts.
	 */
	public function captureCrawler( RequestContext $context, ResponseFacts $response ): ?Hit {
		if ( ! $this->settings->trackCrawlers || ! $this->settings->blockCrawlers ) {
			return null;
		}

		if ( ! $this->trackability->isPageRequest( $context, $response ) ) {
			return null;
		}

		if ( ! $this->trackability->isCrawler( $context ) ) {
			return null;
		}

		$hit = new Hit(
			siteId: get_current_blog_id(),
			path: $this->paths->normalize( $context->path, $context->queryString ),
			visitorHash: self::CRAWLER_HASH,
			sessionKey: self::CRAWLER_HASH,
			timestamp: time(),
			countView: false,
			kind: Hit::KIND_CRAWLER,
			eventName: $this->bots->crawlerName( $context->userAgent )
		);

		$this->writer->write( $hit );

		return $hit;
	}

	/**
	 * Record a server-side event.
	 *
	 * Used by the form and commerce integrations, and by any theme that calls
	 * `honest_analytics_track_event()`. A form submission is a POST followed by
	 * a redirect, so it is never a pageview and cannot be captured as one.
	 *
	 * @param string      $name   Event name.
	 * @param float|null  $value  Optional value.
	 * @param string|null $path   Path the event happened on.
	 * @param int|null    $postId Post it relates to.
	 */
	public function trackEvent( string $name, ?float $value = null, ?string $path = null, ?int $postId = null ): bool {
		$name = trim( $name );

		if ( '' === $name || ! Edition::isPro() || ! $this->settings->enableEvents ) {
			return false;
		}

		$context = RequestContext::current();
		$headers = null !== $context ? $context->headers : [];

		if ( null !== $context && $context->hasPrivacySignal( $this->settings->honourGpc, $this->settings->honourDnt ) ) {
			return false;
		}

		$userAgent = null !== $context ? $context->userAgent : '';

		if ( $this->bots->isBot( $userAgent, $headers ) ) {
			return false;
		}

		$siteId = get_current_blog_id();
		$ip     = $this->clientIp->resolve();
		$hash   = $this->identity->visitorHash( $ip, $userAgent, $siteId );

		if ( null === $path ) {
			// A submission posts to somewhere that is not the page the visitor
			// was on, so the referrer is the only record of where they were.
			//
			// When there is no referrer either - a gateway callback, a form
			// plugin that keeps no source URL - the event is recorded with no
			// page rather than against the home page. The rollup stores that as
			// pathDimId 0, which reads as "we do not know". Filing a shop's
			// entire revenue under `/` because the payment provider's server
			// has no browser behind it would be a confident wrong answer, and
			// this plugin does not give those.
			$referrer = null !== $context ? $context->referrer : '';
			$parsed   = '' !== $referrer ? wp_parse_url( $referrer ) : [];
			$rawPath  = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
			$query    = is_array( $parsed ) && isset( $parsed['query'] ) ? (string) $parsed['query'] : '';
			$path     = '' !== $rawPath ? $this->paths->normalize( $rawPath, $query ) : '';
		} else {
			[ $rawPath, $query ] = PathNormalizer::split( $path );
			$path                = $this->paths->normalize( $rawPath, $query );
		}

		$hit = new Hit(
			siteId: $siteId,
			path: $path,
			visitorHash: $hash,
			sessionKey: $this->identity->sessionKey( $hash, $siteId ),
			timestamp: time(),
			postId: $postId,
			userAgent: $userAgent,
			countView: false,
			kind: Hit::KIND_EVENT,
			eventName: mb_substr( $name, 0, 120 ),
			eventValue: Hit::clampEventValue( $value )
		);

		/** This filter is documented in src/Capture/CaptureService.php */
		$hit = apply_filters( 'honest_analytics_before_track', $hit, $context );

		if ( ! $hit instanceof Hit ) {
			return false;
		}

		$this->writer->write( $hit );

		return true;
	}

	/**
	 * Build the hit for a served page.
	 *
	 * @param RequestContext $context Request snapshot.
	 * @param int            $siteId  Site ID.
	 */
	private function buildHit( RequestContext $context, int $siteId ): Hit {
		// The address lives for the next two lines and is then gone. It is not
		// returned, stored, logged, or used as a cache key.
		$ip          = $this->clientIp->resolve();
		$visitorHash = $this->identity->visitorHash( $ip, $context->userAgent, $siteId );
		$geo         = $this->geo->resolve( $ip );
		unset( $ip );

		$cookies   = $this->cookies();
		$visitorId = $this->consent->resolve( $siteId, $context->headers, $cookies )->isGranted()
			? $this->consent->resolvedVisitorId( $siteId, $cookies )
			: null;

		$searchTerm = null;

		if ( $this->settings->trackSiteSearch && $context->isSearch && '' !== $context->searchTerm ) {
			$searchTerm = mb_substr( mb_strtolower( trim( $context->searchTerm ) ), 0, 200 );
		}

		return new Hit(
			siteId: $siteId,
			path: $this->storedPath( $context ),
			visitorHash: $visitorHash,
			sessionKey: $this->identity->sessionKey( $visitorHash, $siteId ),
			timestamp: time(),
			postId: $context->postId,
			referrer: $this->externalReferrer( $context->referrer ),
			userAgent: $context->userAgent,
			acceptLanguage: $context->acceptLanguage,
			visitorId: $visitorId,
			userId: null !== $visitorId && $this->settings->associateUserId && $context->userId > 0 ? $context->userId : null,
			campaign: $this->settings->enableCampaigns ? Campaign::fromQueryString( $context->queryString ) : null,
			countryCode: $geo['country'] ?? '',
			region: $geo['region'] ?? '',
			searchTerm: $searchTerm
		);
	}

	/**
	 * The path to store for a request.
	 *
	 * A search results page keeps its route but loses the phrase: the term goes
	 * to the search report, and leaving it in the path would mint one page
	 * dimension per phrase anybody ever typed and exhaust the daily cap by
	 * lunchtime. The parameter is kept with an empty value so the results page
	 * is still a distinct row rather than merging into the page it sits on -
	 * on a default install that is the home page, and "/" and "all searches"
	 * are not the same thing.
	 *
	 * @param RequestContext $context Request snapshot.
	 */
	private function storedPath( RequestContext $context ): string {
		if ( ! $context->isSearch ) {
			return $this->paths->normalize( $context->path, $context->queryString );
		}

		$param = $this->settings->siteSearchParam;

		parse_str( $context->queryString, $params );

		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$params[ $param ] = '';

		return $this->paths->normalize( $context->path, http_build_query( $params ) );
	}

	/**
	 * The referrer, but only when it points somewhere else.
	 *
	 * The previous page on this site is not a traffic source, and classifying it
	 * as one would report every interior page as self-referred: true, and
	 * useless.
	 *
	 * @param string $referrer Raw referrer.
	 */
	private function externalReferrer( string $referrer ): string {
		if ( '' === trim( $referrer ) ) {
			return '';
		}

		return Url::isInternal( $referrer ) ? '' : $referrer;
	}

	/**
	 * Request cookies as a plain map.
	 *
	 * @return array<string,string>
	 */
	private function cookies(): array {
		$out = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( (array) $_COOKIE as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$out[ $name ] = (string) $value;
			}
		}

		return $out;
	}

	/**
	 * Whether a page reaching PHP without a nonce means a cache served it.
	 */
	private function isCachedDelivery(): bool {
		return $this->settings->injectScript && $this->settings->isHybrid();
	}

	/**
	 * The path normaliser, for callers that need the same rules.
	 */
	public function paths(): PathNormalizer {
		return $this->paths;
	}

	/**
	 * The trackability rules, for diagnostics.
	 */
	public function trackability(): Trackability {
		return $this->trackability;
	}
}
