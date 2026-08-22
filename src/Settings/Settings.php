<?php
/**
 * Settings value object.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every setting, with the privacy-preserving option as the default in every
 * group. Reading a setting is a property read; nothing here touches the
 * database, so the pipeline classes can be constructed in a test with a plain
 * array.
 */
final class Settings {

	public const TRACKING_SERVER = 'server';
	public const TRACKING_CLIENT = 'client';
	public const TRACKING_HYBRID = 'hybrid';

	public const WRITE_AUTO   = 'auto';
	public const WRITE_SPOOL  = 'spool';
	public const WRITE_DB     = 'db';
	public const WRITE_DIRECT = 'direct';

	public const ENDPOINT_REST  = 'rest';
	public const ENDPOINT_PLAIN = 'plain';

	public const STORE_AUTO  = 'auto';
	public const STORE_CACHE = 'cache';
	public const STORE_DB    = 'db';

	public const UNIQUES_HLL   = 'hll';
	public const UNIQUES_EXACT = 'exact';

	/** A consented visitor cookie may never outlive 24 months. */
	public const CONSENT_COOKIE_MAX_DURATION = 63072000;

	/** Raw journeys may never be kept longer than 26 months. */
	public const JOURNEY_MAX_RETENTION_DAYS = 790;

	/** Aggregates may never be kept longer than 36 months. */
	public const ROLLUP_MAX_RETENTION_MONTHS = 36;

	/** Configured click selectors beyond this many are dropped, not stored. */
	public const MAX_CLICK_SELECTORS = 20;

	// Capture.
	public string $trackingMode    = self::TRACKING_HYBRID;
	public bool $injectScript      = true;
	public string $collectEndpoint = self::ENDPOINT_REST;
	public bool $excludeLoggedIn   = true;
	public int $nonceTtl           = 1800;
	public int $beaconRateLimit    = 120;

	// Paths and query strings.
	/** @var string[] */
	public array $excludePaths = [];
	/** @var string[] */
	public array $excludeQueryParams = [];
	public bool $stripQueryString    = false;

	// Writing and maintenance.
	public string $writeDriver  = self::WRITE_AUTO;
	public bool $autoDrain      = true;
	public int $spoolMaxBytes   = 52428800;
	public int $sessionWindow   = 1800;
	public string $sessionStore = self::STORE_AUTO;

	// Identity and retention.
	public int $saltRotationInterval   = 86400;
	public int $saltRotationHour       = 4;
	public int $hourlyWindowDays       = 7;
	public int $dimensionCap           = 1000;
	public int $rollupRetentionMonths  = 36;
	public string $uniqueCounterDriver = self::UNIQUES_HLL;
	public int $hllPrecision           = 12;

	// Privacy signals.
	public bool $honourGpc = true;
	public bool $honourDnt = false;

	// Network.
	public string $ipSource = 'auto';
	/** @var string[] */
	public array $trustedProxies = [];

	// Crawlers.
	public bool $blockCrawlers = true;
	public bool $trackCrawlers = true;

	// Pro reports.
	public bool $enableCampaigns    = true;
	public string $attributionModel = 'last-click';
	public bool $enableGeo          = false;
	public bool $enableEvents       = true;
	public bool $trackOutbound      = true;
	public bool $trackDownloads     = true;
	/** @var string[] */
	public array $downloadExtensions = [ 'pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'dmg', 'exe', 'pkg', 'rar', 'gz', 'mp3', 'mp4', 'epub' ];
	public bool $trackScroll         = true;
	public bool $trackSiteSearch     = false;
	public string $siteSearchParam   = 's';
	public bool $trackClicks         = true;
	/**
	 * Configured selectors and the event name each records.
	 *
	 * Capped at MAX_CLICK_SELECTORS - each one is one more `closest()` check
	 * on every click a visitor makes, so an unbounded list is a performance
	 * problem nobody watching the reports would ever see coming.
	 *
	 * @var array<int,array{selector:string,eventName:string}>
	 */
	public array $clickSelectors = [];

	// Consent and durable tracking.
	public bool $enableConsent        = false;
	public string $consentCookieName  = '_ha_vid';
	public int $consentCookieDuration = 34186000;
	public string $cmpCookieName      = '';
	/** @var string[] */
	public array $cmpCookieGrantedValues = [ 'granted', 'true', '1', 'yes', 'allow' ];
	public bool $associateUserId         = false;
	public bool $enableJourneys          = false;
	public int $journeyRetentionDays     = 90;
	public int $consentLogRetentionDays  = 0;
	public string $policyVersion         = '1';

	// Third-party integrations. Pro; the WordPress-native surfaces - the
	// dashboard widgets, the post-list column, the editor panel - are not
	// integrations in this sense and are always on.
	public bool $enableIntegrations = true;
	/**
	 * Integrations switched off by hand, by slug.
	 *
	 * A deny list rather than an allow list: an integration should start
	 * working the moment its plugin is activated, without somebody having to
	 * find a checkbox first.
	 *
	 * @var string[]
	 */
	public array $disabledIntegrations = [];
	public bool $trackOrderValue       = true;

	// Scheduled reports.
	public bool $enableScheduledReports = false;
	/** @var string[] */
	public array $reportRecipients = [];
	public string $reportPeriod    = '7d';

	// Traffic alerts.
	public bool $enableAlerts       = false;
	public string $alertSensitivity = 'balanced';
	/** @var string[] */
	public array $alertRecipients = [];

	// Licence and lifecycle.
	public string $licenceKey = '';

	/**
	 * Whether deleting the plugin leaves the analytics tables behind.
	 *
	 * On by default, deliberately. The rollups cannot be rebuilt from anything
	 * else - there is no raw hit data to replay - so the destructive option is
	 * the one somebody has to choose, not the one they get by not reading a
	 * checkbox. Turning it off is a decision, and the Privacy screen says so
	 * before it becomes one.
	 */
	public bool $keepDataOnUninstall = true;

	/**
	 * @param array<string,mixed> $values Raw values, already sanitized.
	 */
	public function __construct( array $values = [] ) {
		foreach ( $values as $key => $value ) {
			if ( ! property_exists( $this, $key ) || null === $value ) {
				continue;
			}

			$this->assign( (string) $key, $value );
		}
	}

	/**
	 * Assign one value, coerced to the declared property type.
	 *
	 * @param string $key   Property name.
	 * @param mixed  $value Raw value.
	 */
	private function assign( string $key, mixed $value ): void {
		$current = $this->{$key};

		if ( is_bool( $current ) ) {
			$this->{$key} = self::toBool( $value );

			return;
		}

		if ( is_int( $current ) ) {
			$this->{$key} = is_numeric( $value ) ? (int) $value : $current;

			return;
		}

		if ( is_array( $current ) ) {
			$this->{$key} = 'clickSelectors' === $key ? self::toPairs( $value ) : self::toList( $value );

			return;
		}

		$this->{$key} = is_scalar( $value ) ? (string) $value : $current;
	}

	/**
	 * The whole set as an array, for saving.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		$out = [];

		foreach ( get_object_vars( $this ) as $key => $value ) {
			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * The defaults, as an array.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return ( new self() )->toArray();
	}

	/**
	 * Whether the beacon runs at all in this mode.
	 */
	public function usesBeacon(): bool {
		return self::TRACKING_SERVER !== $this->trackingMode;
	}

	/**
	 * Whether PHP counts a view in this mode.
	 */
	public function usesServerCapture(): bool {
		return self::TRACKING_CLIENT !== $this->trackingMode;
	}

	/**
	 * Whether the server and the browser both report, and therefore have to be
	 * deduplicated by a nonce.
	 */
	public function isHybrid(): bool {
		return self::TRACKING_HYBRID === $this->trackingMode;
	}

	/**
	 * Whether this configuration could write to a visitor's device.
	 *
	 * Deliberately conservative: it answers "could we", not "have we", because
	 * "a cookie could be set" is the compliance-relevant fact.
	 */
	public function usesDeviceStorage(): bool {
		return $this->enableConsent;
	}

	/**
	 * Milliseconds a session may be idle before the next view starts a new one.
	 */
	public function sessionWindowSeconds(): int {
		return max( 60, $this->sessionWindow );
	}

	/**
	 * Coerce a stored value to bool, accepting the strings WordPress forms send.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function toBool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
		}

		return (bool) $value;
	}

	/**
	 * Coerce a stored value to a list of non-empty strings.
	 *
	 * Accepts an array, or a newline/comma separated string, because both the
	 * settings screen and a wp-config override are legitimate sources.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	public static function toList( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value ) ?: [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$item = reset( $item );
			}

			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = trim( (string) $item );

			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Coerce a stored value to a list of configured click selectors.
	 *
	 * Unlike {@see toList()} each entry is a pair, not a scalar, so it cannot
	 * share that method's flattening - a selector and the event name it
	 * records are two different things and neither is dispensable.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return array<int,array{selector:string,eventName:string}>
	 */
	public static function toPairs( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$selector  = isset( $item['selector'] ) && is_scalar( $item['selector'] ) ? trim( (string) $item['selector'] ) : '';
			$eventName = isset( $item['eventName'] ) && is_scalar( $item['eventName'] ) ? trim( (string) $item['eventName'] ) : '';

			if ( '' === $selector || '' === $eventName ) {
				continue;
			}

			$out[] = [
				'selector'  => $selector,
				'eventName' => $eventName,
			];

			if ( count( $out ) >= self::MAX_CLICK_SELECTORS ) {
				break;
			}
		}

		return $out;
	}
}
