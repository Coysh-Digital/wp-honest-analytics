<?php
/**
 * The real-time endpoint.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rest;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Plugin;
use HonestAnalytics\Stats\RealtimeService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the real-time screen polls.
 *
 * The only route in this plugin that reads rather than writes, and the only one
 * behind a capability check - every fifteen seconds, from an open dashboard, so
 * it has to be cheap. It is: the whole answer comes from the session layer,
 * which is a single indexed read, and no report table is touched.
 *
 * The "still in progress" test lives in the session store, not here. It has to
 * accept a closed-batch column that is either NULL or an empty string, because
 * `$wpdb->prepare()` renders a PHP null as '' for a %s placeholder and a plain
 * `IS NULL` therefore matches nothing - a bug whose symptom is a real-time
 * screen that confidently reports nobody is on the site.
 */
final class RealtimeController {

	/** More visits than anybody reads off a screen, and a bound on the payload. */
	private const MAX_LIMIT = 200;

	private RealtimeService $realtime;

	/**
	 * @param RealtimeService $realtime Real-time reports.
	 */
	public function __construct( RealtimeService $realtime ) {
		$this->realtime = $realtime;
	}

	/**
	 * Build one from the container.
	 */
	public static function make(): self {
		return new self( Plugin::instance()->realtime() );
	}

	/**
	 * The route callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? min( self::MAX_LIMIT, $limit ) : 50;

		$response = new \WP_REST_Response( $this->realtime->snapshot( get_current_blog_id(), null, $limit ) );

		// A polled endpoint that a proxy is willing to cache stops being
		// real-time without anybody noticing.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Who may ask.
	 */
	public static function permission(): bool {
		return current_user_can( Capabilities::VIEW );
	}
}
