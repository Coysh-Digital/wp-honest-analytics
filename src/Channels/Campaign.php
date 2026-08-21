<?php
/**
 * Campaign tags.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Channels;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The campaign parameters carried in a URL.
 *
 * Read before the path is normalised, so a tagged and an untagged view of the
 * same page collapse onto one row in the Pages report while the attribution
 * still survives here.
 */
final class Campaign {

	/**
	 * Parameters recognised as campaign tags, and stripped from stored paths.
	 *
	 * @var string[]
	 */
	public const PARAMS = [
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'utm_source_platform',
		'gclid',
		'gbraid',
		'wbraid',
		'dclid',
		'fbclid',
		'msclkid',
		'twclid',
		'ttclid',
		'li_fat_id',
		'igshid',
		'mc_cid',
		'mc_eid',
		'ref',
		'referrer',
	];

	/** Longest value stored per field. */
	private const MAX_LENGTH = 200;

	public readonly string $source;
	public readonly string $medium;
	public readonly string $campaign;
	public readonly string $term;
	public readonly string $content;

	/**
	 * @param string $source   Source.
	 * @param string $medium   Medium.
	 * @param string $campaign Campaign name.
	 * @param string $term     Term.
	 * @param string $content  Content.
	 */
	public function __construct(
		string $source,
		string $medium = '',
		string $campaign = '',
		string $term = '',
		string $content = ''
	) {
		$this->source   = self::clean( $source );
		$this->medium   = self::clean( $medium );
		$this->campaign = self::clean( $campaign );
		$this->term     = self::clean( $term );
		$this->content  = self::clean( $content );
	}

	/**
	 * Read the campaign out of a query string, if there is one.
	 *
	 * A campaign with no source is not a campaign: an ad click id on its own
	 * still counts, and is mapped to the network that issued it.
	 *
	 * @param string $queryString Raw query string.
	 */
	public static function fromQueryString( string $queryString ): ?self {
		if ( '' === trim( $queryString ) ) {
			return null;
		}

		parse_str( $queryString, $params );

		$normalised = [];

		foreach ( $params as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			// An HTML-entity-encoded link ("&amp;utm_source=") survives
			// parse_str as a key with an "amp;" prefix. A doubly-encoded one
			// arrives with two. Strip the lot.
			$clean = preg_replace( '/^(?:amp;)+/', '', $key );

			if ( is_string( $clean ) && is_scalar( $value ) ) {
				$normalised[ strtolower( $clean ) ] = (string) $value;
			}
		}

		$source = $normalised['utm_source'] ?? '';

		if ( '' === trim( $source ) ) {
			$source = self::sourceFromClickId( $normalised );
		}

		if ( '' === trim( $source ) ) {
			return null;
		}

		$medium = $normalised['utm_medium'] ?? '';

		if ( '' === trim( $medium ) ) {
			$medium = self::mediumFromClickId( $normalised );
		}

		return new self(
			$source,
			$medium,
			$normalised['utm_campaign'] ?? ( $normalised['utm_id'] ?? '' ),
			$normalised['utm_term'] ?? '',
			$normalised['utm_content'] ?? ''
		);
	}

	/**
	 * The network behind a bare ad click id.
	 *
	 * @param array<string,string> $params Query parameters.
	 */
	private static function sourceFromClickId( array $params ): string {
		foreach ( [
			'gclid'     => 'google',
			'gbraid'    => 'google',
			'wbraid'    => 'google',
			'dclid'     => 'google',
			'msclkid'   => 'bing',
			'fbclid'    => 'facebook',
			'twclid'    => 'twitter',
			'ttclid'    => 'tiktok',
			'li_fat_id' => 'linkedin',
		] as $param => $source ) {
			if ( '' !== trim( $params[ $param ] ?? '' ) ) {
				return $source;
			}
		}

		return '';
	}

	/**
	 * The medium implied by a bare ad click id.
	 *
	 * @param array<string,string> $params Query parameters.
	 */
	private static function mediumFromClickId( array $params ): string {
		foreach ( [ 'gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid' ] as $param ) {
			if ( '' !== trim( $params[ $param ] ?? '' ) ) {
				return 'cpc';
			}
		}

		foreach ( [ 'fbclid', 'twclid', 'ttclid', 'li_fat_id' ] as $param ) {
			if ( '' !== trim( $params[ $param ] ?? '' ) ) {
				return 'social';
			}
		}

		return '';
	}

	/**
	 * A stable key for deduplicating touches within a session.
	 */
	public function key(): string {
		return implode( '|', [ $this->source, $this->medium, $this->campaign, $this->term, $this->content ] );
	}

	/**
	 * As an array, for the spool line.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return [
			's' => $this->source,
			'm' => $this->medium,
			'c' => $this->campaign,
			't' => $this->term,
			'o' => $this->content,
		];
	}

	/**
	 * From a spool line array.
	 *
	 * @param array<string,mixed> $data Array.
	 */
	public static function fromArray( array $data ): ?self {
		$source = isset( $data['s'] ) && is_scalar( $data['s'] ) ? (string) $data['s'] : '';

		if ( '' === trim( $source ) ) {
			return null;
		}

		return new self(
			$source,
			isset( $data['m'] ) && is_scalar( $data['m'] ) ? (string) $data['m'] : '',
			isset( $data['c'] ) && is_scalar( $data['c'] ) ? (string) $data['c'] : '',
			isset( $data['t'] ) && is_scalar( $data['t'] ) ? (string) $data['t'] : '',
			isset( $data['o'] ) && is_scalar( $data['o'] ) ? (string) $data['o'] : ''
		);
	}

	/**
	 * Normalise one field.
	 *
	 * Lower-cased so "Newsletter" and "newsletter" are one campaign rather than
	 * two rows that have to be added up by eye.
	 *
	 * @param string $value Raw value.
	 */
	private static function clean( string $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		$value = is_string( $value ) ? trim( $value ) : '';

		return mb_substr( mb_strtolower( $value ), 0, self::MAX_LENGTH );
	}
}
