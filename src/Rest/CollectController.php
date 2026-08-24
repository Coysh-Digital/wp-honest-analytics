<?php
/**
 * The beacon endpoint.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

use HonestAnalytics\Bots\BotFilter;
use HonestAnalytics\Capture\Hit;
use HonestAnalytics\Capture\NonceRegistry;
use HonestAnalytics\Capture\PathNormalizer;
use HonestAnalytics\Capture\ShutdownRunner;
use HonestAnalytics\Channels\Campaign;
use HonestAnalytics\Consent\ConsentService;
use HonestAnalytics\Devices\Device;
use HonestAnalytics\Devices\DeviceParser;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Geo\GeoService;
use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Plugin;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\ClientIp;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Server;
use HonestAnalytics\Support\Url;
use HonestAnalytics\Write\WriterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the browser sends, and what becomes of it.
 *
 * The beacon carries almost nothing: a path, sometimes a one-time nonce,
 * sometimes how long the visitor stayed. Everything else this endpoint knows -
 * who the visitor is for the rest of the day, where they came from, what
 * browser they are using, roughly where in the world they are - is worked out
 * here from the request itself and then reduced to counters.
 *
 * The address is the part to watch. It exists inside `identify()` for the two
 * lines it takes to salt-hash it and ask the geo database which country it is
 * in, and is then unset. It is never returned, never logged, never used as a
 * cache key and never written anywhere.
 *
 * The user agent and the referrer are reduced here too, before the hit is
 * written rather than when it is aggregated: four device families and a bare
 * origin. Neither string exists past this method.
 *
 * Every path out of here answers 204. See NoContent for why.
 */
final class CollectController {

	/** Two hours. Longer than that is a tab somebody left open, not a read. */
	private const MAX_DWELL_MS = 7200000;

	/** What a scroll depth is allowed to be. */
	private const SCROLL_BUCKETS = [ 25, 50, 75, 100 ];

	public function __construct(
		private Settings $settings,
		private PathNormalizer $paths,
		private IdentityService $identity,
		private NonceRegistry $nonces,
		private BotFilter $bots,
		private GeoService $geo,
		private ConsentService $consent,
		private WriterInterface $writer,
		private ClientIp $clientIp,
		private RateLimit $limits,
		private DeviceParser $deviceParser
	) {
	}

	/**
	 * Build one from the container.
	 */
	public static function make(): self {
		$plugin = Plugin::instance();

		return new self(
			$plugin->settings(),
			$plugin->paths(),
			$plugin->identity(),
			$plugin->nonces(),
			$plugin->bots(),
			$plugin->geo(),
			$plugin->consent(),
			$plugin->writer(),
			$plugin->clientIp(),
			new RateLimit( StoreFactory::keyValue() ),
			$plugin->devices()
		);
	}

	/**
	 * The REST route callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest( \WP_REST_Request $request ): \WP_REST_Response {
		$this->run( self::bodyParams( $request ), self::headers( $request ) );

		return NoContent::response();
	}

	/**
	 * Handle a beacon.
	 *
	 * Never throws. A beacon that cannot be understood is a number that does
	 * not appear in a report; a beacon that fatals is a 500 in somebody's
	 * console on every page of their site.
	 *
	 * @param array<string,string> $params  Posted fields.
	 * @param array<string,string> $headers Request headers, lower-cased.
	 */
	public function run( array $params, array $headers ): void {
		ShutdownRunner::markCollectRequest();

		try {
			$this->collect( $params, $headers );
		} catch ( \Throwable $e ) {
			Log::warning( 'A beacon could not be recorded: ' . $e->getMessage() );
		}
	}

	/**
	 * The work.
	 *
	 * @param array<string,string> $params  Posted fields.
	 * @param array<string,string> $headers Request headers, lower-cased.
	 */
	private function collect( array $params, array $headers ): void {
		if ( ! $this->settings->usesBeacon() ) {
			return;
		}

		// The signal is checked before anything else is read, and before any
		// hashing happens. Honouring it only after building an identity would
		// be honouring it in name.
		if ( $this->hasPrivacySignal( $headers ) ) {
			return;
		}

		$userAgent = $headers['user-agent'] ?? '';

		if ( $this->bots->isBot( $userAgent, $headers ) ) {
			return;
		}

		$raw = trim( (string) ( $params['p'] ?? '' ) );

		if ( '' === $raw ) {
			return;
		}

		[ $rawPath, $queryString ] = PathNormalizer::split( mb_substr( $raw, 0, 2000 ) );

		$path = $this->paths->normalize( $this->paths->fromBeaconPath( $rawPath ), $queryString );

		if ( $this->paths->isExcluded( $path ) ) {
			return;
		}

		$siteId = get_current_blog_id();

		// The address lives here and nowhere else.
		$ip          = $this->clientIp->resolve();
		$visitorHash = $this->identity->visitorHash( $ip, $userAgent, $siteId );
		$geo         = $this->geo->resolve( $ip );
		// Not the address: a value derived from it, for one rate-limit key,
		// which RateLimit hashes again before it becomes one. It goes no
		// further than the two lines below.
		$addressKey = '' === $ip ? '' : hash( 'sha256', $ip );
		unset( $ip );

		if ( $this->limits->exceeded( 'beacon', $visitorHash, $this->settings->beaconRateLimit ) ) {
			return;
		}

		// A second, much looser bucket on the address itself.
		//
		// The visitor bucket is keyed on a hash of the address *and the user
		// agent*, so a caller who varies the user agent gets a fresh allowance
		// every request and the first limit stops meaning anything. This one
		// cannot be varied. It is deliberately generous - a shared office
		// address or a mobile carrier's NAT is a great many real people behind
		// one address, and dropping their pageviews to inconvenience a script
		// would be the wrong trade - so it is sized to stop one machine minting
		// thousands of identities a minute, not to police a network.
		//
		// The address is hashed into the key by RateLimit and is not stored,
		// exactly as the visitor hash is not.
		if ( '' !== $addressKey
			&& $this->limits->exceeded( 'beacon-addr', $addressKey, $this->addressCeiling() ) ) {
			return;
		}

		$kind = $this->kind( (string) ( $params['k'] ?? '' ) );

		if ( null === $kind ) {
			return;
		}

		$isEngagement = Hit::KIND_VIEW === $kind && '' !== (string) ( $params['e'] ?? '' );
		$countView    = Hit::KIND_VIEW === $kind && ! $isEngagement;

		if ( $countView && ! $this->claimView( $params, $visitorHash ) ) {
			// PHP counted this view as it built the page. The beacon has nothing
			// to add - the engagement beacon will follow with the dwell time.
			return;
		}

		$cookies   = Server::cookies();
		$visitorId = $this->consent->resolve( $siteId, $headers, $cookies )->isGranted()
			? $this->consent->resolvedVisitorId( $siteId, $cookies )
			: null;

		$hit = new Hit(
			siteId: $siteId,
			path: $this->storedPath( $path, $queryString ),
			visitorHash: $visitorHash,
			sessionKey: $this->identity->sessionKey( $visitorHash, $siteId ),
			timestamp: time(),
			referrer: $this->referrer( $headers ),
			// Reduced here, in the request that saw it. See Devices\Device.
			device: (string) Device::fromUserAgent( $this->deviceParser, $userAgent ),
			dwellMs: $isEngagement ? $this->dwell( $params ) : 0,
			countView: $countView,
			visitorId: $visitorId,
			campaign: $this->settings->enableCampaigns ? Campaign::fromQueryString( $queryString ) : null,
			countryCode: $geo['country'] ?? '',
			region: $geo['region'] ?? '',
			kind: $kind,
			eventName: $this->eventName( $kind, $params ),
			eventValue: Hit::KIND_EVENT === $kind ? Hit::clampEventValue( $params['ev'] ?? null ) : null,
			target: $this->target( $kind, $params ),
			scrollBucket: $this->scrollBucket( $params ),
			searchTerm: $this->searchTerm( $queryString )
		);

		/** This filter is documented in src/Capture/CaptureService.php */
		$hit = apply_filters( 'honest_analytics_before_track', $hit, null );

		if ( ! $hit instanceof Hit ) {
			return;
		}

		$this->writer->write( $hit );
	}

	/**
	 * How many beacons one address may send in a window.
	 *
	 * A generous multiple of the per-visitor limit rather than a figure of its
	 * own, so that a site which has raised or lowered one has raised or lowered
	 * both. Filterable for the site that really does have a thousand people
	 * behind one address.
	 */
	private function addressCeiling(): int {
		$ceiling = max( 600, $this->settings->beaconRateLimit * 30 );

		/**
		 * Filters how many beacons a single address may send per minute.
		 *
		 * @param int $ceiling Requests per window. Zero disables the limit.
		 */
		return (int) apply_filters( 'honest_analytics_beacon_address_limit', $ceiling );
	}

	/**
	 * Whether this beacon may count a view.
	 *
	 * In hybrid mode a nonce in the page means PHP rendered it and has already
	 * counted the view, so the beacon claims the nonce and stands down. No
	 * nonce means the HTML came from a cache and PHP never saw the request, so
	 * the beacon is the only thing that can count it.
	 *
	 * The nonce is claimed against the visitor as well as itself, so one nonce
	 * baked into a cached page is claimed by exactly the visitor it was
	 * rendered for, and everybody else who receives that same HTML is counted.
	 *
	 * @param array<string,string> $params      Posted fields.
	 * @param string               $visitorHash Visitor hash.
	 */
	private function claimView( array $params, string $visitorHash ): bool {
		if ( ! $this->settings->isHybrid() ) {
			return true;
		}

		$nonce = trim( (string) ( $params['n'] ?? '' ) );

		if ( '' === $nonce || ! NonceRegistry::isWellFormed( $nonce ) ) {
			return true;
		}

		return ! $this->nonces->claim( $nonce, $visitorHash );
	}

	/**
	 * The kind of hit this beacon describes, or null if it is not one we take.
	 *
	 * @param string $raw The `k` field.
	 */
	private function kind( string $raw ): ?string {
		if ( '' === $raw || Hit::KIND_VIEW === $raw ) {
			return Hit::KIND_VIEW;
		}

		if ( ! Edition::isPro() || ! $this->settings->enableEvents ) {
			return null;
		}

		return match ( $raw ) {
			Hit::KIND_EVENT    => Hit::KIND_EVENT,
			Hit::KIND_OUTBOUND => $this->settings->trackOutbound ? Hit::KIND_OUTBOUND : null,
			Hit::KIND_DOWNLOAD => $this->settings->trackDownloads ? Hit::KIND_DOWNLOAD : null,
			default            => null,
		};
	}

	/**
	 * The event name, where the kind has one.
	 *
	 * @param string               $kind   Hit kind.
	 * @param array<string,string> $params Posted fields.
	 */
	private function eventName( string $kind, array $params ): ?string {
		if ( Hit::KIND_EVENT !== $kind ) {
			return null;
		}

		$name = trim( (string) ( $params['en'] ?? '' ) );

		return '' === $name ? null : mb_substr( $name, 0, 120 );
	}

	/**
	 * The clicked URL, for outbound clicks and downloads.
	 *
	 * @param string               $kind   Hit kind.
	 * @param array<string,string> $params Posted fields.
	 */
	private function target( string $kind, array $params ): ?string {
		if ( ! in_array( $kind, [ Hit::KIND_OUTBOUND, Hit::KIND_DOWNLOAD ], true ) ) {
			return null;
		}

		$target = trim( (string) ( $params['t'] ?? '' ) );

		if ( '' === $target ) {
			return null;
		}

		$target = esc_url_raw( $target, [ 'http', 'https' ] );

		return '' === $target ? null : mb_substr( $target, 0, 500 );
	}

	/**
	 * How long the visitor stayed, in milliseconds.
	 *
	 * @param array<string,string> $params Posted fields.
	 */
	private function dwell( array $params ): int {
		$value = $params['d'] ?? 0;

		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, min( self::MAX_DWELL_MS, (int) $value ) );
	}

	/**
	 * How far down the page the visitor read.
	 *
	 * @param array<string,string> $params Posted fields.
	 */
	private function scrollBucket( array $params ): ?int {
		if ( ! Edition::isPro() || ! $this->settings->enableEvents || ! $this->settings->trackScroll ) {
			return null;
		}

		$value = $params['s'] ?? null;

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$bucket = (int) $value;

		return in_array( $bucket, self::SCROLL_BUCKETS, true ) ? $bucket : null;
	}

	/**
	 * The search phrase, when this was a search results page.
	 *
	 * @param string $queryString The path's query string.
	 */
	private function searchTerm( string $queryString ): ?string {
		if ( ! $this->settings->trackSiteSearch || '' === $queryString ) {
			return null;
		}

		parse_str( $queryString, $params );

		$term = is_array( $params ) ? ( $params[ $this->settings->siteSearchParam ] ?? '' ) : '';

		if ( ! is_string( $term ) || '' === trim( $term ) ) {
			return null;
		}

		return mb_substr( mb_strtolower( trim( $term ) ), 0, 200 );
	}

	/**
	 * The path to store.
	 *
	 * A search results page keeps its route and loses the phrase, exactly as
	 * the server-side path does: the term belongs in the search report, and
	 * leaving it here would mint one page dimension per phrase anybody typed.
	 *
	 * @param string $path        Normalised path.
	 * @param string $queryString The query string it was built from.
	 */
	private function storedPath( string $path, string $queryString ): string {
		if ( null === $this->searchTerm( $queryString ) ) {
			return $path;
		}

		[ $bare, $query ] = PathNormalizer::split( $path );

		parse_str( $query, $params );

		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$params[ $this->settings->siteSearchParam ] = '';

		return $this->paths->normalize( $bare, http_build_query( $params ) );
	}

	/**
	 * The referrer, but only when it points somewhere else.
	 *
	 * @param array<string,string> $headers Request headers.
	 */
	private function referrer( array $headers ): string {
		// The beacon is posted from the page being counted, so its referrer is
		// that page - which is this site, and therefore not a traffic source.
		// A cross-site arrival is recorded by the session's first hit instead.
		//
		// Scheme and host only, and nothing after them: see Url::externalOrigin.
		return Url::externalOrigin( $headers['referer'] ?? '' );
	}

	/**
	 * Whether the visitor has asked not to be tracked.
	 *
	 * @param array<string,string> $headers Request headers.
	 */
	private function hasPrivacySignal( array $headers ): bool {
		if ( $this->settings->honourGpc && '1' === ( $headers['sec-gpc'] ?? '' ) ) {
			return true;
		}

		return $this->settings->honourDnt && '1' === ( $headers['dnt'] ?? '' );
	}

	/**
	 * A REST request's headers, lower-cased and flattened.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string,string>
	 */
	public static function headers( \WP_REST_Request $request ): array {
		$out = [];

		foreach ( (array) $request->get_headers() as $name => $values ) {
			$out[ str_replace( '_', '-', strtolower( (string) $name ) ) ] = is_array( $values )
				? (string) reset( $values )
				: (string) $values;
		}

		return $out;
	}

	/**
	 * The posted fields, however the body arrived.
	 *
	 * `sendBeacon()` with a URLSearchParams body sends form encoding and the
	 * REST server parses it. A proxy that rewrites the content type, or a
	 * browser that sends it as a blob, leaves `get_body_params()` empty with a
	 * perfectly good body sitting behind it - so the raw body is parsed as a
	 * fallback rather than the hit being dropped.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string,string>
	 */
	public static function bodyParams( \WP_REST_Request $request ): array {
		$params = self::flatten( (array) $request->get_body_params() );

		if ( [] !== $params ) {
			return $params;
		}

		parse_str( (string) $request->get_body(), $parsed );

		return is_array( $parsed ) ? self::flatten( $parsed ) : [];
	}

	/**
	 * Reduce posted fields to strings.
	 *
	 * A beacon body is form-encoded, but nothing stops somebody posting arrays
	 * at it, and every reader downstream expects a scalar.
	 *
	 * Keys are `array-key` rather than `string` because they genuinely can be
	 * integers: `parse_str()` turns a field named `0` into an int key, and this
	 * runs on a body anybody can post. The `is_string()` guard below is what
	 * drops them, so the signature has to admit them first.
	 *
	 * @param array<array-key,mixed> $params Posted fields.
	 *
	 * @return array<string,string>
	 */
	public static function flatten( array $params ): array {
		$out = [];

		foreach ( $params as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}

		return $out;
	}
}
