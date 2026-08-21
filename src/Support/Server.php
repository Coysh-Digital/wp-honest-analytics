<?php
/**
 * Request environment access.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place that touches $_SERVER, so the capture path can be tested by handing
 * it an array rather than by mutating superglobals.
 */
class Server {

	/**
	 * The server array.
	 *
	 * @var array<string,mixed>
	 */
	private array $server;

	/**
	 * @param array<string,mixed>|null $server Server array; defaults to $_SERVER.
	 */
	public function __construct( ?array $server = null ) {
		// Read whole and raw. Every accessor below sanitizes for the meaning
		// it needs, which is the only point at which the right rule is known.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$this->server = $server ?? $_SERVER;
	}

	/**
	 * A raw server value as a string.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): string {
		$value = $this->server[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a server key is present and non-empty.
	 *
	 * @param string $key Key.
	 */
	public function has( string $key ): bool {
		return '' !== $this->get( $key );
	}

	/**
	 * A request header by its lower-case name (e.g. `accept-language`).
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );

		if ( 'content-type' === $name || 'content-length' === $name ) {
			$direct = $this->get( strtoupper( str_replace( '-', '_', $name ) ) );

			if ( '' !== $direct ) {
				return $direct;
			}
		}

		return $this->get( $key );
	}

	/**
	 * Every request header, keyed by lower-case name.
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		$headers = [];

		foreach ( $this->server as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'HTTP_' ) ) {
				continue;
			}

			$name             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
			$headers[ $name ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $headers;
	}

	/**
	 * The request method, upper-cased.
	 */
	public function method(): string {
		return strtoupper( $this->get( 'REQUEST_METHOD' ) );
	}

	/**
	 * The full request URI, as the browser sent it.
	 */
	public function requestUri(): string {
		return $this->get( 'REQUEST_URI' );
	}

	/**
	 * The user agent string.
	 */
	public function userAgent(): string {
		return $this->header( 'user-agent' );
	}

	/**
	 * The referring URL, as the browser sent it.
	 *
	 * Only ever read server-side: a browser-supplied referrer on the beacon is
	 * forgeable, so the collect endpoint never reads one.
	 */
	public function referrer(): string {
		return $this->header( 'referer' );
	}

	/**
	 * The raw remote address.
	 *
	 * Never stored. It exists to be hashed and dropped inside the same frame.
	 */
	public function remoteAddr(): string {
		return $this->get( 'REMOTE_ADDR' );
	}
}
