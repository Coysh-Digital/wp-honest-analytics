<?php
/**
 * What a visitor was browsing on, reduced.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Devices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four facts kept about a browser, and nothing that identifies one.
 *
 * This exists so that the user-agent string is discarded at the edge rather
 * than at the drain. It used to travel whole - into the spool file, into the
 * sessions table - and only became a browser family and an operating system
 * minutes later, when the aggregation ran. That was a full user agent on disk
 * for as long as the drain took, on a site whose whole claim is that it does
 * not keep one, and it made "the full user-agent string is discarded" true of
 * the reports and not of the machine.
 *
 * So the parse happens once, in the request that saw it, and this is what
 * carries onwards. A browser family, its major version, an operating system
 * family and a form factor: enough for every device report, and not enough to
 * pick one visitor out of a thousand.
 *
 * The wire format is deliberately legible - `Chrome|130|macOS|1` - because a
 * spool line is something a person reads when they are working out why a
 * number looks wrong, and an opaque blob would only invite them to go and
 * find the original.
 */
final class Device {

	/** What separates the four fields. Absent from every value that goes in them. */
	private const SEPARATOR = '|';

	/**
	 * @param string     $browser Browser family, e.g. Chrome.
	 * @param int        $major   Its major version, or 0.
	 * @param string     $os      Operating system family, e.g. macOS.
	 * @param DeviceType $type    Form factor.
	 */
	public function __construct(
		public readonly string $browser = 'Unknown',
		public readonly int $major = 0,
		public readonly string $os = 'Unknown',
		public readonly DeviceType $type = DeviceType::Unknown
	) {
	}

	/**
	 * Reduce a user agent to this, and let the string go.
	 *
	 * @param DeviceParser $parser    The parser.
	 * @param string       $userAgent Raw user agent. Not retained.
	 */
	public static function fromUserAgent( DeviceParser $parser, string $userAgent ): self {
		if ( '' === trim( $userAgent ) ) {
			return new self();
		}

		[ $browser, $major, $os, $type ] = $parser->parse( $userAgent );

		return new self( self::clean( $browser ), $major, self::clean( $os ), $type );
	}

	/**
	 * Whether anything was learned at all.
	 */
	public function isUnknown(): bool {
		return 'Unknown' === $this->browser && 'Unknown' === $this->os && DeviceType::Unknown === $this->type;
	}

	/**
	 * The stored form.
	 *
	 * An empty string for a device nothing is known about, so that
	 * {@see \HonestAnalytics\Capture\Hit::toArray()} drops the field entirely
	 * rather than writing four unknowns to every line.
	 */
	public function __toString(): string {
		if ( $this->isUnknown() ) {
			return '';
		}

		// Cleaned here rather than only in fromUserAgent(), so the encoding
		// holds however this object was built. A user agent is whatever the
		// caller felt like sending, including this separator.
		return implode(
			self::SEPARATOR,
			[ self::clean( $this->browser ), (string) $this->major, self::clean( $this->os ), (string) $this->type->value ]
		);
	}

	/**
	 * Read the stored form back.
	 *
	 * Tolerant on purpose. This parses a value that may have been written by an
	 * older build, sat in a spool file across an upgrade, or been truncated by
	 * a filesystem having a bad day - and the answer to all three is a device
	 * nobody knows anything about, not an exception on the drain path.
	 *
	 * @param string $stored The stored form.
	 */
	public static function fromString( string $stored ): self {
		$stored = trim( $stored );

		if ( '' === $stored ) {
			return new self();
		}

		$parts = explode( self::SEPARATOR, $stored );

		if ( count( $parts ) < 4 ) {
			return new self();
		}

		return new self(
			self::clean( $parts[0] ),
			max( 0, (int) $parts[1] ),
			self::clean( $parts[2] ),
			DeviceType::fromStored( (int) $parts[3] )
		);
	}

	/**
	 * A field value that cannot break the encoding or overflow the column.
	 *
	 * The parsing library reports what the user-agent string says, and a user
	 * agent is whatever the caller felt like sending - including a separator,
	 * or a kilobyte of it.
	 *
	 * @param string $value Raw field value.
	 */
	private static function clean( string $value ): string {
		$value = trim( str_replace( self::SEPARATOR, ' ', $value ) );

		return '' === $value ? 'Unknown' : mb_substr( $value, 0, 60 );
	}
}
