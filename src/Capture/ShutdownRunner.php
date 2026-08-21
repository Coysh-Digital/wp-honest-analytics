<?php
/**
 * Capture after the response has gone.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Plugin;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Write\AutoDrain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that happens after the visitor has their page.
 *
 * The ordering here is the whole performance story. The response is flushed
 * first, the connection closed, and only then is anything counted - so the
 * measurable cost to the person waiting is zero, and the work happens on a
 * worker that is no longer holding anybody up.
 *
 * Registered at the very end of `shutdown` so that debug bars, profilers and
 * page caches - which write their cache file from an output buffer at priority
 * 1 - have all finished with the response before the connection goes.
 */
final class ShutdownRunner {

	/**
	 * Whether a collect request was served this cycle.
	 */
	private static bool $servedCollect = false;

	/**
	 * Attach the hook.
	 */
	public static function register(): void {
		add_action( 'shutdown', [ self::class, 'run' ], PHP_INT_MAX );
	}

	/**
	 * Note that this request was a beacon.
	 *
	 * On a fully cached site the beacons are the only requests that reach PHP
	 * at all, so if the automatic drain only ran on rendered pages, a cached
	 * site without cron would never drain.
	 */
	public static function markCollectRequest(): void {
		self::$servedCollect = true;
	}

	/**
	 * Run the post-response work.
	 */
	public static function run(): void {
		$context = RequestContext::current();

		if ( null === $context && ! self::$servedCollect ) {
			return;
		}

		self::finishRequest();

		if ( null !== $context ) {
			try {
				self::capture( $context );
			} catch ( \Throwable $e ) {
				Log::warning( 'Capture failed: ' . $e->getMessage() );
			}
		}

		try {
			self::autoDrain();
		} catch ( \Throwable $e ) {
			Log::warning( 'Automatic drain failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Send the response and let the visitor go.
	 *
	 * Without a FastCGI-style handoff - mod_php, for instance - there is nothing
	 * to call, and the work simply happens with the connection still open. It is
	 * a millisecond or two, and `ignore_user_abort` keeps a visitor who closes
	 * the tab from killing the write halfway through.
	 */
	private static function finishRequest(): void {
		ignore_user_abort( true );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();

			return;
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Count the page, or the crawler that asked for it.
	 *
	 * @param RequestContext $context Request snapshot.
	 */
	private static function capture( RequestContext $context ): void {
		$plugin   = Plugin::instance();
		$response = ResponseFacts::current();
		$capture  = $plugin->capture();

		if ( $capture->trackability()->isCrawler( $context ) ) {
			$capture->captureCrawler( $context, $response );

			return;
		}

		$capture->capture( $context, $response );
	}

	/**
	 * Run the throttled traffic-triggered drain.
	 */
	private static function autoDrain(): void {
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();

		if ( Settings::WRITE_DIRECT === $settings->writeDriver ) {
			return;
		}

		( new AutoDrain( $settings ) )->run( static fn () => Plugin::instance()->drainer() );
	}
}
