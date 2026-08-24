<?php
/**
 * A Google connection's tokens.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

use HonestAnalytics\Support\Secret;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Google hands back, and what has to be kept.
 *
 * The refresh token is the durable half and the one worth protecting: it is
 * read-only access to somebody's analytics until they revoke it. It is never
 * returned to a REST response, never printed, and never logged - there is a
 * `redacted()` for the times something wants to describe this object.
 */
final class Ga4Tokens {

	/**
	 * @param string $accessToken  Short-lived, used on every request.
	 * @param string $refreshToken Durable. The one that matters.
	 * @param int    $expiresAt    Unix time the access token stops working.
	 * @param string $scope        What Google actually granted.
	 */
	public function __construct(
		public readonly string $accessToken,
		public readonly string $refreshToken,
		public readonly int $expiresAt,
		public readonly string $scope = ''
	) {
	}

	/**
	 * Whether the access token is still good.
	 *
	 * Sixty seconds of slack, because a token that expires between the check
	 * and the request is a confusing failure to debug and a cheap one to avoid.
	 *
	 * @param int|null $now Timestamp, for tests.
	 */
	public function isFresh( ?int $now = null ): bool {
		return '' !== $this->accessToken && $this->expiresAt > ( $now ?? time() ) + 60;
	}

	/**
	 * Rebuild from a Google token response.
	 *
	 * Google omits `refresh_token` on a refresh, so the old one is carried
	 * forward. Dropping it there would silently turn a working connection into
	 * one that stops in an hour.
	 *
	 * @param array<string,mixed> $data             Decoded JSON.
	 * @param string              $existingRefresh  The refresh token already held.
	 * @param int|null            $now              Timestamp, for tests.
	 */
	public static function fromResponse( array $data, string $existingRefresh = '', ?int $now = null ): self {
		$expiresIn = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

		return new self(
			isset( $data['access_token'] ) ? (string) $data['access_token'] : '',
			isset( $data['refresh_token'] ) && '' !== (string) $data['refresh_token']
				? (string) $data['refresh_token']
				: $existingRefresh,
			( $now ?? time() ) + max( 0, $expiresIn ),
			isset( $data['scope'] ) ? (string) $data['scope'] : ''
		);
	}

	/**
	 * For storage.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		// Encrypted going in, and only here. One refresh token is read access
		// to somebody's Google account until it is revoked, and wp_options is
		// reachable from any read-only database exposure. See Support\Secret,
		// including what happens when a site's salts are rotated.
		return [
			'accessToken'  => Secret::encrypt( $this->accessToken ),
			'refreshToken' => Secret::encrypt( $this->refreshToken ),
			'expiresAt'    => $this->expiresAt,
			'scope'        => $this->scope,
		];
	}

	/**
	 * Rebuild from storage.
	 *
	 * @param mixed $data Whatever came out of the option.
	 */
	public static function fromArray( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['refreshToken'] ) ) {
			return null;
		}

		$refreshToken = Secret::decrypt( (string) $data['refreshToken'] );

		// A token that will not decrypt is a connection that no longer exists -
		// the ordinary cause is rotated site salts - and the honest answer is
		// "not connected", not a half-built object that fails at the first
		// call to Google.
		if ( '' === $refreshToken ) {
			return null;
		}

		return new self(
			isset( $data['accessToken'] ) ? Secret::decrypt( (string) $data['accessToken'] ) : '',
			$refreshToken,
			isset( $data['expiresAt'] ) ? (int) $data['expiresAt'] : 0,
			isset( $data['scope'] ) ? (string) $data['scope'] : ''
		);
	}

	/**
	 * A description safe to log or dump.
	 *
	 * @return array<string,mixed>
	 */
	public function redacted(): array {
		return [
			'accessToken'  => '' !== $this->accessToken ? '[set]' : '[none]',
			'refreshToken' => '' !== $this->refreshToken ? '[set]' : '[none]',
			'expiresAt'    => $this->expiresAt,
			'scope'        => $this->scope,
		];
	}
}
