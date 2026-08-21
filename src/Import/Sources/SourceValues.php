<?php
/**
 * Canonical names for things other plugins spell differently.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Sources;

use HonestAnalytics\Devices\DeviceType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One spelling per thing.
 *
 * A device report listing `Mobile`, `mobile`, `smartphone` and `Phone` as four
 * separate rows is worse than no device report, and that is exactly what
 * happens when two sources' vocabularies land in one table untouched. Every
 * imported dimension value passes through here on its way in.
 *
 * Anything genuinely unrecognised keeps its own name rather than being forced
 * into a bucket it does not belong in. An honest `Vivaldi` is better than a
 * confident `Other`.
 */
final class SourceValues {

	/**
	 * A device category, from whatever the source called it.
	 *
	 * @param string $raw      The source's word for it.
	 * @param string $platform The operating system, used when the source has no device column.
	 */
	public static function deviceType( string $raw, string $platform = '' ): DeviceType {
		$value = strtolower( trim( $raw ) );

		$device = match ( $value ) {
			'desktop', 'pc', 'computer', 'laptop'                     => DeviceType::Desktop,
			'mobile', 'smartphone', 'phone', 'mobile phone', 'handset' => DeviceType::Mobile,
			'tablet', 'ipad', 'phablet'                                => DeviceType::Tablet,
			default                                                    => null,
		};

		if ( null !== $device ) {
			return $device;
		}

		// No device column, or a word nobody recognises. The operating system
		// is the next best evidence: a source that knows it was iOS knows more
		// than it realises.
		return match ( strtolower( trim( $platform ) ) ) {
			'ios', 'iphone', 'android'      => DeviceType::Mobile,
			'ipad'                          => DeviceType::Tablet,
			'windows', 'macos', 'mac os x', 'macintosh', 'linux', 'chromeos', 'cros' => DeviceType::Desktop,
			default                         => DeviceType::Unknown,
		};
	}

	/**
	 * A browser family name people recognise.
	 *
	 * @param string $raw The source's name for it.
	 */
	public static function browser( string $raw ): string {
		$value = strtolower( trim( $raw ) );

		if ( '' === $value ) {
			return '';
		}

		return match ( $value ) {
			'chrome', 'google chrome', 'chrome mobile', 'headlesschrome' => 'Chrome',
			'firefox', 'mozilla firefox', 'mozilla'                      => 'Firefox',
			'safari', 'mobile safari'                                    => 'Safari',
			'edge', 'microsoft edge', 'edg', 'edga', 'edgios'            => 'Edge',
			'opera', 'opr'                                               => 'Opera',
			'ie', 'msie', 'internet explorer', 'trident'                 => 'Internet Explorer',
			'samsungbrowser', 'samsung internet', 'samsung browser'      => 'Samsung Internet',
			'unknown', 'other', '-'                                      => '',
			default                                                      => self::titleCase( $raw ),
		};
	}

	/**
	 * An operating system family name people recognise.
	 *
	 * The same mapping the native device parser applies, so that an imported
	 * `Macintosh` and a natively recorded `macOS` are one row rather than two.
	 *
	 * @param string $raw The source's name for it.
	 */
	public static function platform( string $raw ): string {
		$value = strtolower( trim( $raw ) );

		if ( '' === $value ) {
			return '';
		}

		return match ( $value ) {
			'macintosh', 'os x', 'mac os x', 'macos', 'mac'  => 'macOS',
			'windows', 'windows nt', 'win', 'win32', 'win64' => 'Windows',
			'iphone', 'ipad', 'ipod', 'ios'                  => 'iOS',
			'android'                                        => 'Android',
			'linux', 'gnu/linux', 'ubuntu', 'debian'         => 'Linux',
			'cros', 'chrome os', 'chromeos'                  => 'ChromeOS',
			'unknown', 'other', '-'                          => '',
			default                                          => self::titleCase( $raw ),
		};
	}

	/**
	 * A browser's major version, from whatever the source stored.
	 *
	 * @param string $raw Version string, such as `126.0.6478.127`.
	 */
	public static function majorVersion( string $raw ): int {
		if ( 1 === preg_match( '/(\d+)/', $raw, $matches ) ) {
			return (int) min( 32767, max( 0, (int) $matches[1] ) );
		}

		return 0;
	}

	/**
	 * A two-letter country code, or an empty string.
	 *
	 * @param string $raw Whatever the source stored.
	 */
	public static function country( string $raw ): string {
		$value = strtoupper( trim( $raw ) );

		// Sources use a variety of placeholders for "we could not tell".
		if ( in_array( $value, [ '', 'XX', 'ZZ', '000', 'UNKNOWN', '-' ], true ) ) {
			return '';
		}

		return 1 === preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
	}

	/**
	 * Capitalise a name without mangling one that is already right.
	 *
	 * @param string $raw Raw name.
	 */
	private static function titleCase( string $raw ): string {
		$value = trim( $raw );

		if ( '' === $value ) {
			return '';
		}

		// Already mixed case - the source knows what it is called better than a
		// blanket ucfirst() does.
		if ( strtolower( $value ) !== $value ) {
			return substr( $value, 0, 60 );
		}

		return substr( ucfirst( $value ), 0, 60 );
	}
}
