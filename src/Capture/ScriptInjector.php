<?php
/**
 * Front-end tracker injection.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

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

	public const HANDLE = 'honest-analytics';

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
		add_filter( 'wp_script_attributes', [ $this, 'addDataAttributes' ] );
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

		/**
		 * Fires after the tracker is enqueued, on a page it will count.
		 *
		 * Where anything that measures more than a page view adds its own
		 * script. `ScriptInjector::HANDLE` is enqueued by now, so a script
		 * added here can depend on it.
		 *
		 * @param Settings $settings The settings in force.
		 */
		do_action( 'honest_analytics_enqueue_tracker_extras', $this->settings );
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
	 * Hooked on `wp_script_attributes`, which hands over the attribute array of
	 * the tag core is building, rather than on `script_loader_tag`, where the
	 * value is `$translations . $before_script . <script src> . $after_script`
	 * and the old implementation put its attributes on the *first* `<script` in
	 * it. Any third party calling `wp_add_inline_script( 'honest-analytics', ...,
	 * 'before' )` - consent managers and optimisers do it routinely - therefore
	 * took `data-endpoint` and `data-nonce` onto their own tag, `tracker.js`
	 * found no endpoint and returned, and **counting stopped entirely** with
	 * nothing in the console to say so. The hybrid nonce was emitted on a
	 * stranger's element into the bargain.
	 *
	 * `mixed` rather than `array` because this is a public filter under
	 * universal strict_types: a plugin that returns null from it would
	 * otherwise fatal the front end here rather than wherever it went wrong.
	 *
	 * @param mixed $attributes Attributes for the tag core is about to print.
	 *
	 * @return array<string,string|bool>
	 */
	public function addDataAttributes( mixed $attributes ): array {
		$attributes = is_array( $attributes ) ? $attributes : [];

		$id     = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
		$handle = str_ends_with( $id, '-js' ) ? substr( $id, 0, -3 ) : '';

		/**
		 * Filters which script handles this plugin decorates.
		 *
		 * A handle listed here gets the optimiser markers below and a chance to
		 * add attributes of its own. Anything enqueued on
		 * `honest_analytics_enqueue_tracker_extras` wants to be in this list,
		 * or an optimiser will defer it away from the tag it depends on.
		 *
		 * @param string[] $handles Script handles.
		 */
		$handles = (array) apply_filters( 'honest_analytics_tracker_handles', [ self::HANDLE ] );

		if ( ! in_array( $handle, $handles, true ) ) {
			return $attributes;
		}

		// Merged onto what core built, not substituted for it: `src`, `id` and
		// the loading strategy are already in here and the tag needs them.
		$attributes['data-no-optimize'] = '1';
		$attributes['data-no-minify']   = '1';
		$attributes['data-no-defer']    = '1';
		$attributes['data-cfasync']     = 'false';

		if ( self::HANDLE === $handle ) {
			$this->tagPrinted = true;

			$attributes['data-endpoint'] = $this->collectUrl();

			if ( null !== $this->pendingNonce ) {
				$attributes['data-nonce'] = $this->pendingNonce;
			}
		}

		/**
		 * Filters the attributes on one of this plugin's script tags.
		 *
		 * The tracker's own are set above. This is where a script added on
		 * `honest_analytics_enqueue_tracker_extras` configures itself, by the
		 * same mechanism and for the same reason: a tag that reads its own
		 * attributes survives an optimiser that moves it and a policy that
		 * forbids inline script.
		 *
		 * @param array<string,string|bool> $attributes The attributes so far.
		 * @param string                    $handle     The handle being printed.
		 * @param Settings                  $settings   The settings in force.
		 */
		return (array) apply_filters( 'honest_analytics_script_attributes', $attributes, $handle, $this->settings );
	}


	/**
	 * Note that a theme template rendered.
	 *
	 * A page served from a full-page cache never reaches this, which is how the
	 * capture path tells "PHP built this page" from "somebody's cache did".
	 *
	 * `mixed` rather than `string`, because this runs on `template_include` at
	 * PHP_INT_MAX under universal strict_types. Null is never coerced into a
	 * userland string parameter - it is a TypeError - so a plugin that returns
	 * null from that filter would have taken the front end down here rather
	 * than wherever the mistake actually was.
	 *
	 * @param mixed $template Template path.
	 */
	public function markTemplateRendered( mixed $template ): mixed {
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
