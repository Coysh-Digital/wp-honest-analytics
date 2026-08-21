<?php
/**
 * User agent parsing.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Devices;

use donatj\UserAgent\UserAgentParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A user agent becomes four small facts and is then forgotten.
 *
 * A browser name and a major version, an operating system, and a device shape.
 * Never the full string: "Chrome/126.0.6478.127" is forty thousand distinct
 * values a year, which is unbounded cardinality in exchange for a level of
 * detail nobody reports on, and a fingerprinting surface nobody asked for.
 *
 * This runs in the drain rather than on the request thread.
 */
final class DeviceParser {

	/** Checked first: an iPad also says "Safari" and would otherwise read as desktop. */
	private const TABLET_MARKERS = [ 'ipad', 'tablet', 'playbook', 'silk', 'kindle' ];

	private const MOBILE_MARKERS = [ 'mobile', 'iphone', 'ipod', 'android', 'phone', 'blackberry', 'opera mini' ];

	/** Anything higher is a spoof, and a smallint inside a unique key has to survive it. */
	private const MAX_MAJOR_VERSION = 999;

	/**
	 * The library parser, when it is installed.
	 */
	private ?UserAgentParser $parser = null;

	/**
	 * Parse a user agent.
	 *
	 * @param string $userAgent User agent string.
	 *
	 * @return array{0:string,1:int,2:string,3:DeviceType}
	 */
	public function parse( string $userAgent ): array {
		$userAgent = trim( $userAgent );

		if ( '' === $userAgent ) {
			return [ 'Unknown', 0, 'Unknown', DeviceType::Unknown ];
		}

		[ $browser, $version, $platform ] = $this->split( $userAgent );

		return [
			'' !== $browser ? $browser : 'Unknown',
			self::majorVersion( $version ),
			self::normalisePlatform( $platform ),
			self::deviceType( $userAgent, $platform ),
		];
	}

	/**
	 * Names people recognise.
	 *
	 * The parsing library reports what the user-agent string literally says,
	 * which is how a report ends up listing "Macintosh" - a word Apple stopped
	 * using in about 1998. A report is read by a person, so it says macOS.
	 *
	 * @param string $platform Raw platform name.
	 */
	private static function normalisePlatform( string $platform ): string {
		$platform = trim( $platform );

		if ( '' === $platform ) {
			return 'Unknown';
		}

		return match ( strtolower( $platform ) ) {
			'macintosh', 'os x', 'mac os x' => 'macOS',
			'windows', 'windows nt'         => 'Windows',
			'iphone', 'ipad', 'ipod'        => 'iOS',
			'linux'                         => 'Linux',
			'cros', 'chrome os'             => 'ChromeOS',
			default                         => $platform,
		};
	}

	/**
	 * Browser, version and platform, from the library or the fallback.
	 *
	 * @param string $userAgent User agent string.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	private function split( string $userAgent ): array {
		if ( class_exists( UserAgentParser::class ) ) {
			try {
				$this->parser ??= new UserAgentParser();
				$parsed         = $this->parser->parse( $userAgent );

				return [
					(string) $parsed->browser(),
					(string) $parsed->browserVersion(),
					(string) $parsed->platform(),
				];
			} catch ( \Throwable ) {
				// Fall through to the built-in matcher.
			}
		}

		return self::fallbackSplit( $userAgent );
	}

	/**
	 * A deliberately small built-in matcher.
	 *
	 * Used when the parsing library is not installed - a git checkout without
	 * `composer install`, say. It gets the common cases right and says
	 * "Unknown" rather than guessing at the rest, which is the honest failure
	 * for a report somebody will read as fact.
	 *
	 * @param string $userAgent User agent string.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	public static function fallbackSplit( string $userAgent ): array {
		$ua = $userAgent;

		$browsers = [
			'Edge'              => '/\bEdgA?\/([0-9.]+)/i',
			'Opera'             => '/\bOPR\/([0-9.]+)/i',
			'Samsung Internet'  => '/\bSamsungBrowser\/([0-9.]+)/i',
			'Firefox'           => '/\bFirefox\/([0-9.]+)/i',
			'Chrome'            => '/\bChrome\/([0-9.]+)/i',
			'Safari'            => '/\bVersion\/([0-9.]+).*\bSafari\//i',
			'Internet Explorer' => '/\bMSIE ([0-9.]+)|\brv:([0-9.]+)\) like Gecko/i',
		];

		$browser = '';
		$version = '';

		foreach ( $browsers as $name => $pattern ) {
			if ( 1 === preg_match( $pattern, $ua, $matches ) ) {
				$browser = $name;
				// The Internet Explorer pattern is an alternation, so on an
				// rv: match group 1 is set but empty rather than absent -
				// which is why this tests the value, not the key.
				$version = '' !== $matches[1] ? $matches[1] : ( $matches[2] ?? '' );

				break;
			}
		}

		$platforms = [
			'iOS'       => '/\b(iPhone|iPad|iPod)\b/i',
			'Android'   => '/\bAndroid\b/i',
			'Windows'   => '/\bWindows NT\b/i',
			'macOS'     => '/\b(Mac OS X|Macintosh)\b/i',
			'Linux'     => '/\bLinux\b/i',
			'Chrome OS' => '/\bCrOS\b/i',
		];

		$platform = '';

		foreach ( $platforms as $name => $pattern ) {
			if ( 1 === preg_match( $pattern, $ua ) ) {
				$platform = $name;

				break;
			}
		}

		return [ $browser, $version, $platform ];
	}

	/**
	 * The major version, or 0 when it is missing or implausible.
	 *
	 * Clamping to the ceiling would invent a version nobody runs; zero already
	 * means "unknown" everywhere else, so a spoofed "Chrome/73469" reads as
	 * unknown rather than as a record-breaking release.
	 *
	 * @param string $version Full version string.
	 */
	private static function majorVersion( string $version ): int {
		$first = explode( '.', trim( $version ) )[0] ?? '';

		if ( ! ctype_digit( $first ) ) {
			return 0;
		}

		$major = (int) $first;

		return ( $major < 0 || $major > self::MAX_MAJOR_VERSION ) ? 0 : $major;
	}

	/**
	 * The device shape.
	 *
	 * @param string $userAgent User agent string.
	 * @param string $platform  Detected platform.
	 */
	private static function deviceType( string $userAgent, string $platform ): DeviceType {
		$ua = strtolower( $userAgent );

		foreach ( self::TABLET_MARKERS as $marker ) {
			if ( str_contains( $ua, $marker ) ) {
				return DeviceType::Tablet;
			}
		}

		foreach ( self::MOBILE_MARKERS as $marker ) {
			if ( str_contains( $ua, $marker ) ) {
				return DeviceType::Mobile;
			}
		}

		return '' === $platform ? DeviceType::Unknown : DeviceType::Desktop;
	}
}
