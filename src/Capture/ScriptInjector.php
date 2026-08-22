<?php
/**
 * Front-end tracker injection.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Consent\ConsentService;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the tracker on the page, and knows whether it got there.
 *
 * That second job is the subtle one. `wp_footer()` is a convention, not a
 * guarantee: a theme can omit it, an optimiser can strip the tag, an AMP
 * sanitiser will certainly remove it. If the tag never printed, the nonce was
 * never shipped, and recording it would mean waiting for a beacon that is never
 * coming - so the view would be counted by nobody. Tracking whether the tag
 * actually rendered is what turns that into "degrade to server-only for this
 * page" instead.
 */
final class ScriptInjector {

	public const HANDLE         = 'honest-analytics';
	public const HANDLE_PRO     = 'honest-analytics-pro';
	public const HANDLE_CONSENT = 'honest-analytics-consent';

	private Settings $settings;

	/**
	 * The nonce minted for this page, if any.
	 */
	private ?string $pendingNonce = null;

	/**
	 * Whether the tracker tag was actually printed.
	 */
	private bool $tagPrinted = false;

	/**
	 * Whether a theme template rendered at all.
	 */
	private bool $templateRendered = false;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Attach the front-end hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_filter( 'script_loader_tag', [ $this, 'addDataAttributes' ], 10, 3 );
		add_filter( 'template_include', [ $this, 'markTemplateRendered' ], PHP_INT_MAX );
	}

	/**
	 * Enqueue the tracker.
	 */
	public function enqueue(): void {
		if ( ! $this->shouldInject() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			HONEST_ANALYTICS_URL . 'assets/js/tracker.js',
			[],
			HONEST_ANALYTICS_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		if ( $this->settings->isHybrid() ) {
			$this->pendingNonce = ( new NonceRegistry( $this->settings ) )->issue();
		}

		if ( Edition::isPro() && $this->settings->enableEvents ) {
			wp_enqueue_script(
				self::HANDLE_PRO,
				HONEST_ANALYTICS_URL . 'assets/js/pro.js',
				[ self::HANDLE ],
				HONEST_ANALYTICS_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}

		if ( Edition::isPro() && $this->settings->enableConsent ) {
			wp_enqueue_script(
				self::HANDLE_CONSENT,
				HONEST_ANALYTICS_URL . 'assets/js/consent.js',
				[ self::HANDLE ],
				HONEST_ANALYTICS_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}
	}

	/**
	 * Add the configuration to the script tag.
	 *
	 * Attributes rather than an inline script, deliberately. An inline config
	 * object is the first thing a strict Content-Security-Policy blocks and the
	 * first thing an optimiser moves; the tracker reads its own tag, which
	 * survives both. The `nowprocket`, `data-cfasync` and `data-no-optimize`
	 * markers ask the common optimisers to leave the tag where it is.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source.
	 */
	public function addDataAttributes( string $tag, string $handle, string $src ): string {
		unset( $src );

		if ( ! in_array( $handle, [ self::HANDLE, self::HANDLE_PRO, self::HANDLE_CONSENT ], true ) ) {
			return $tag;
		}

		$attributes = [
			'data-no-optimize' => '1',
			'data-no-minify'   => '1',
			'data-no-defer'    => '1',
			'data-cfasync'     => 'false',
		];

		if ( self::HANDLE === $handle ) {
			$this->tagPrinted = true;

			$attributes['data-endpoint'] = $this->collectUrl();

			if ( null !== $this->pendingNonce ) {
				$attributes['data-nonce'] = $this->pendingNonce;
			}
		}

		if ( self::HANDLE_PRO === $handle ) {
			$attributes['data-endpoint']        = $this->collectUrl();
			$attributes['data-events']          = $this->settings->enableEvents ? '1' : '0';
			$attributes['data-outbound']        = $this->settings->enableEvents && $this->settings->trackOutbound ? '1' : '0';
			$attributes['data-downloads']       = $this->settings->enableEvents && $this->settings->trackDownloads ? '1' : '0';
			$attributes['data-scroll']          = $this->settings->enableEvents && $this->settings->trackScroll ? '1' : '0';
			$attributes['data-extensions']      = implode( ',', $this->settings->downloadExtensions );
			$attributes['data-clicks']          = $this->settings->enableEvents && $this->settings->trackClicks ? '1' : '0';
			$attributes['data-click-attribute'] = 'data-honest-event';
			$attributes['data-click-selectors'] = self::encodeClickSelectors( $this->settings->clickSelectors );
		}

		if ( self::HANDLE_CONSENT === $handle ) {
			$attributes['data-consent-endpoint'] = $this->consentUrl();
		}

		$rendered = '';

		foreach ( $attributes as $name => $value ) {
			$rendered .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return (string) preg_replace( '/<script\s/', '<script' . $rendered . ' ', $tag, 1 );
	}

	/**
	 * Pack the configured selectors into one attribute value.
	 *
	 * "=>" separates a pair and "||" separates pairs - neither sequence is
	 * valid CSS, so there is nothing a configured selector could contain that
	 * would be mistaken for the packing itself.
	 *
	 * @param array<int,array{selector:string,eventName:string}> $pairs Configured selectors.
	 */
	private static function encodeClickSelectors( array $pairs ): string {
		$encoded = array_map(
			static fn ( array $pair ): string => $pair['selector'] . '=>' . $pair['eventName'],
			$pairs
		);

		return implode( '||', $encoded );
	}

	/**
	 * Note that a theme template rendered.
	 *
	 * A page served from a full-page cache never reaches this, which is how the
	 * capture path tells "PHP built this page" from "somebody's cache did".
	 *
	 * @param string $template Template path.
	 */
	public function markTemplateRendered( string $template ): string {
		$this->templateRendered = true;

		return $template;
	}

	/**
	 * The nonce minted for this page, if the tag actually printed.
	 */
	public function pendingNonce(): ?string {
		return $this->tagPrinted ? $this->pendingNonce : null;
	}

	/**
	 * Whether the tracker tag was printed.
	 */
	public function tagPrinted(): bool {
		return $this->tagPrinted;
	}

	/**
	 * Whether a theme template rendered.
	 */
	public function templateRendered(): bool {
		return $this->templateRendered;
	}

	/**
	 * Whether the tracker belongs on this page.
	 */
	private function shouldInject(): bool {
		if ( ! $this->settings->injectScript || ! $this->settings->usesBeacon() ) {
			return false;
		}

		$context = RequestContext::current();

		if ( null === $context ) {
			return false;
		}

		if ( $context->hasPrivacySignal( $this->settings->honourGpc, $this->settings->honourDnt ) ) {
			return false;
		}

		// A page we would never count does not need a beacon that would be
		// ignored on arrival.
		$normalizer = new PathNormalizer( $this->settings );
		$path       = $normalizer->normalize( $context->path, $context->queryString );

		if ( $normalizer->isExcluded( $path ) ) {
			return false;
		}

		/**
		 * Filters whether the tracker script is added to this page.
		 *
		 * @param bool $inject Whether to inject.
		 */
		return (bool) apply_filters( 'honest_analytics_inject_tracker', true );
	}

	/**
	 * The URL the beacon posts to.
	 *
	 * Root-relative on purpose: it is baked into cached HTML, so it has to
	 * survive a staging clone, a protocol change behind a proxy and domain
	 * mapping without pointing a visitor's browser at another site.
	 */
	public function collectUrl(): string {
		return self::endpointUrl( $this->settings, 'collect' );
	}

	/**
	 * The URL consent decisions post to.
	 */
	public function consentUrl(): string {
		return self::endpointUrl( $this->settings, 'consent' );
	}

	/**
	 * Build one of the public endpoint URLs.
	 *
	 * @param Settings $settings Settings.
	 * @param string   $route    'collect' or 'consent'.
	 */
	public static function endpointUrl( Settings $settings, string $route ): string {
		if ( Settings::ENDPOINT_PLAIN === $settings->collectEndpoint ) {
			$url = add_query_arg( 'honest-analytics', $route, home_url( '/' ) );
		} else {
			$url = rest_url( 'honest-analytics/v1/' . $route );
		}

		/**
		 * Filters the public endpoint URL the tracker posts to.
		 *
		 * @param string $url   Absolute URL.
		 * @param string $route 'collect' or 'consent'.
		 */
		$url = (string) apply_filters( 'honest_analytics_endpoint_url', $url, $route );

		return Url::relative( $url );
	}
}
