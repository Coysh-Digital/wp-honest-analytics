<?php
/**
 * Client IP resolution.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the visitor's address for the length of one hash.
 *
 * WordPress has no notion of a trusted proxy, so behind Cloudflare or a load
 * balancer every visitor arrives with the proxy's REMOTE_ADDR and the whole
 * site collapses to one visitor per user agent. That has to be solved, but
 * `X-Forwarded-For` is attacker-controlled: anyone who can set it can mint
 * unlimited visitor hashes and walk straight through a rate limit keyed on
 * one. So the default trusts REMOTE_ADDR, and every other source - including
 * the explicit Cloudflare one - is believed only when the connection genuinely
 * came from a proxy this site was told to trust, or from Cloudflare's own
 * published ranges.
 *
 * The address returned here is never written anywhere. It is an input to a
 * keyed hash and is discarded with the stack frame.
 */
final class ClientIp {

	public const SOURCE_AUTO            = 'auto';
	public const SOURCE_REMOTE_ADDR     = 'remote_addr';
	public const SOURCE_CF_CONNECTING   = 'cf_connecting_ip';
	public const SOURCE_X_FORWARDED_FOR = 'x_forwarded_for';
	public const SOURCE_X_REAL_IP       = 'x_real_ip';

	/**
	 * Cloudflare's published edge ranges.
	 *
	 * Refreshed per release from https://www.cloudflare.com/ips/ - a runtime
	 * fetch would be a third-party call on the capture path, which this plugin
	 * does not make.
	 *
	 * @var string[]
	 */
	private const CLOUDFLARE_RANGES = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	private Server $server;
	private Settings $settings;

	/**
	 * @param Server   $server   Request environment.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Server $server, Settings $settings ) {
		$this->server   = $server;
		$this->settings = $settings;
	}

	/**
	 * The address to hash for this request.
	 *
	 * Returns an empty string when nothing usable is available; the hash still
	 * works, it simply has one fewer input.
	 */
	public function resolve(): string {
		$remote = $this->server->remoteAddr();

		switch ( $this->settings->ipSource ) {
			case self::SOURCE_REMOTE_ADDR:
				return $this->normalize( $remote );

			case self::SOURCE_CF_CONNECTING:
				// Verified, not taken on trust. Cloudflare overwrites this
				// header at its edge, so behind Cloudflare it is reliable - but
				// an origin that is still reachable directly gets the header
				// straight from the caller, and then anybody can mint an
				// unlimited number of visitor hashes, walk through the beacon
				// rate limit with a fresh bucket every request, and file their
				// traffic under whichever country they fancy. The `auto` branch
				// below has always checked this; the explicit setting did not.
				return $this->normalize( $this->trustedHeader( 'cf-connecting-ip', $remote ) );

			case self::SOURCE_X_REAL_IP:
				return $this->normalize( $this->trustedHeader( 'x-real-ip', $remote ) );

			case self::SOURCE_X_FORWARDED_FOR:
				return $this->normalize( $this->forwardedFor( $remote ) );

			case self::SOURCE_AUTO:
			default:
				if ( $this->isCloudflare( $remote ) ) {
					$forwarded = $this->server->header( 'cf-connecting-ip' );

					if ( '' !== $forwarded ) {
						return $this->normalize( $forwarded );
					}
				}

				if ( $this->isTrustedProxy( $remote ) ) {
					return $this->normalize( $this->forwardedFor( $remote ) );
				}

				return $this->normalize( $remote );
		}
	}

	/**
	 * The nearest forwarded address that no trusted proxy vouched for.
	 *
	 * Read right to left, which is the only direction that means anything.
	 * Every proxy worth the name *appends*: nginx's
	 * `proxy_add_x_forwarded_for`, HAProxy's `forwardfor`, an AWS load
	 * balancer and Cloudflare all put whatever the caller sent first and the
	 * address they actually saw last. So the left-most entry is not the
	 * visitor - it is the one part of the header the visitor chose, and taking
	 * it hands anybody who asks an unlimited supply of visitor hashes, a fresh
	 * rate-limit bucket every request, and any country they care to be in.
	 *
	 * Walking backwards past the hops we were told to trust stops at the
	 * address that reached the outermost of them, which is the visitor. If
	 * every entry is a trusted proxy - a health checker inside the network,
	 * say - there is no visitor address to find and the peer is the honest
	 * answer.
	 *
	 * @param string $remote Remote address.
	 */
	private function forwardedFor( string $remote ): string {
		if ( ! $this->isTrustedProxy( $remote ) && ! $this->isCloudflare( $remote ) ) {
			return $remote;
		}

		$header = $this->server->header( 'x-forwarded-for' );

		if ( '' === $header ) {
			return $remote;
		}

		$parts = array_map( 'trim', explode( ',', $header ) );

		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			$candidate = $this->normalize( $parts[ $i ] );

			if ( '' === $candidate ) {
				// Unparseable, and therefore not something to trust or to
				// return. Keep walking rather than stopping here: a proxy that
				// wrote "unknown" is not the end of the chain.
				continue;
			}

			if ( $this->isTrustedProxy( $candidate ) || $this->isCloudflare( $candidate ) ) {
				continue;
			}

			return $candidate;
		}

		return $remote;
	}

	/**
	 * A header value, but only from a proxy we were told to trust.
	 *
	 * @param string $name   Header name.
	 * @param string $remote Remote address.
	 */
	private function trustedHeader( string $name, string $remote ): string {
		if ( ! $this->isTrustedProxy( $remote ) && ! $this->isCloudflare( $remote ) ) {
			return $remote;
		}

		$value = $this->server->header( $name );

		return '' !== $value ? $value : $remote;
	}

	/**
	 * Whether an address is one of the configured trusted proxies.
	 *
	 * @param string $ip Address.
	 */
	private function isTrustedProxy( string $ip ): bool {
		foreach ( $this->settings->trustedProxies as $range ) {
			if ( self::inRange( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address belongs to Cloudflare.
	 *
	 * @param string $ip Address.
	 */
	private function isCloudflare( string $ip ): bool {
		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			if ( self::inRange( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Trim an address to something worth hashing.
	 *
	 * @param string $ip Address.
	 */
	private function normalize( string $ip ): string {
		$ip = trim( $ip );

		if ( '' === $ip ) {
			return '';
		}

		// Strip an IPv4 port, but never an IPv6 colon.
		if ( 1 === substr_count( $ip, ':' ) && str_contains( $ip, '.' ) ) {
			$ip = strtok( $ip, ':' ) ?: $ip;
		}

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an address falls inside a CIDR range (or equals a bare address).
	 *
	 * @param string $ip    Address.
	 * @param string $range CIDR range or single address.
	 */
	public static function inRange( string $ip, string $range ): bool {
		$ip    = trim( $ip );
		$range = trim( $range );

		if ( '' === $ip || '' === $range ) {
			return false;
		}

		if ( ! str_contains( $range, '/' ) ) {
			return $ip === $range;
		}

		[ $subnet, $bits ] = explode( '/', $range, 2 );

		if ( ! ctype_digit( $bits ) ) {
			return false;
		}

		$bits = (int) $bits;
		// inet_pton() emits a warning as well as returning false for anything
		// that is not an address, and "not an address" is an ordinary outcome
		// here. The return value is checked on the next line.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ipBinary  = @inet_pton( $ip );
		$netBinary = @inet_pton( $subnet );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ipBinary || false === $netBinary || strlen( $ipBinary ) !== strlen( $netBinary ) ) {
			return false;
		}

		$bytes     = intdiv( $bits, 8 );
		$remainder = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ipBinary, substr( $netBinary, 0, $bytes ), 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		if ( ! isset( $ipBinary[ $bytes ], $netBinary[ $bytes ] ) ) {
			return false;
		}

		$mask = ~( ( 1 << ( 8 - $remainder ) ) - 1 ) & 0xFF;

		return ( ord( $ipBinary[ $bytes ] ) & $mask ) === ( ord( $netBinary[ $bytes ] ) & $mask );
	}
}
