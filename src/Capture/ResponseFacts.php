<?php
/**
 * What the response turned out to be.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read at shutdown, when the answer is finally knowable.
 *
 * A request that looked like a page at `wp` may have redirected, died, or
 * served a JSON error since. PHP's own status code is authoritative by then:
 * `status_header()`, `wp_redirect()` and `wp_die()` all go through `header()`,
 * so whatever `http_response_code()` reports is what the visitor received.
 */
final class ResponseFacts {

	private function __construct(
		public readonly int $status,
		public readonly string $contentType
	) {
	}

	/**
	 * Read the facts from the current response.
	 */
	public static function current(): self {
		$status      = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		$contentType = '';

		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'content-type:' ) ) {
					$contentType = strtolower( trim( substr( $header, 13 ) ) );
				}
			}
		}

		return new self( $status > 0 ? $status : 200, $contentType );
	}

	/**
	 * Build a set of facts directly. For tests.
	 *
	 * @param int    $status      Status code.
	 * @param string $contentType Content type header value.
	 */
	public static function make( int $status, string $contentType = 'text/html; charset=UTF-8' ): self {
		return new self( $status, strtolower( $contentType ) );
	}

	/**
	 * Whether the visitor received an HTML page.
	 *
	 * An absent Content-Type means PHP's `default_mimetype`, which is HTML.
	 */
	public function isHtml(): bool {
		if ( '' === $this->contentType ) {
			return true;
		}

		return str_contains( $this->contentType, 'text/html' )
			|| str_contains( $this->contentType, 'application/xhtml+xml' );
	}

	/**
	 * Whether the response succeeded.
	 */
	public function isOk(): bool {
		return 200 === $this->status;
	}
}
