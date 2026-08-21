<?php
/**
 * Where the Google connection is kept.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages thrown here are technical detail for the log and are never printed; Ga4Exception::friendly() is what any screen shows, and escaping a log line would corrupt the only copy of the diagnostic.

/**
 * The connection, and the one place a token is read from or written to.
 *
 * Not autoloaded. A refresh token is read-only access to somebody's analytics
 * until it is revoked, and there is no reason for it to be in memory on every
 * front-end request on the site.
 *
 * Nothing here hands a token to a caller except `provider()`, which needs one
 * to talk to Google. `describe()` exists for everything else - the screens, the
 * REST responses, the log - so that "show me the connection" never accidentally
 * means "print the credentials".
 */
final class TokenStore {

	/** Not autoloaded, and never returned to a browser. */
	public const OPTION = 'honest_analytics_ga4_connection';

	/**
	 * The whole stored record.
	 *
	 * @return array<string,mixed>
	 */
	private static function record(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Write the record back, keeping it out of the autoload set.
	 *
	 * @param array<string,mixed> $record The record.
	 */
	private static function save( array $record ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, [], '', false );
		}

		update_option( self::OPTION, $record, false );
	}

	/**
	 * The tokens, or null when this site is not connected.
	 */
	public static function tokens(): ?Ga4Tokens {
		return Ga4Tokens::fromArray( self::record()['tokens'] ?? null );
	}

	/**
	 * Remember a fresh set of tokens.
	 *
	 * @param Ga4Tokens $tokens     What Google returned.
	 * @param string    $providerId Which provider obtained them.
	 */
	public static function remember( Ga4Tokens $tokens, string $providerId ): void {
		$record             = self::record();
		$record['tokens']   = $tokens->toArray();
		$record['provider'] = $providerId;
		$record['since']    = $record['since'] ?? time();

		self::save( $record );
	}

	/**
	 * Whether this site has a Google connection.
	 */
	public static function isConnected(): bool {
		return null !== self::tokens();
	}

	/**
	 * Which provider the connection was made through.
	 */
	public static function providerId(): string {
		return (string) ( self::record()['provider'] ?? '' );
	}

	/**
	 * The chosen property, as `properties/123456789`, or ''.
	 */
	public static function property(): string {
		return (string) ( self::record()['property'] ?? '' );
	}

	/**
	 * What that property is called, for the screens.
	 *
	 * @return array{name:string,displayName:string,url:string,timezone:string,currency:string}
	 */
	public static function propertyDetail(): array {
		$detail = self::record()['propertyDetail'] ?? [];
		$detail = is_array( $detail ) ? $detail : [];

		return [
			'name'        => (string) ( $detail['name'] ?? self::property() ),
			'displayName' => (string) ( $detail['displayName'] ?? '' ),
			'url'         => (string) ( $detail['url'] ?? '' ),
			'timezone'    => (string) ( $detail['timezone'] ?? '' ),
			'currency'    => (string) ( $detail['currency'] ?? '' ),
		];
	}

	/**
	 * Choose the property to import from.
	 *
	 * @param Property $property The property.
	 */
	public static function chooseProperty( Property $property ): void {
		$record                   = self::record();
		$record['property']       = $property->name;
		$record['propertyDetail'] = $property->toArray();

		// The available range belongs to the property that was chosen, so a
		// different choice must not inherit the last one's dates.
		unset( $record['range'] );

		self::save( $record );
	}

	/**
	 * The date range this property has data for, if it has been looked up.
	 *
	 * @return array{from:string,to:string,checkedAt:int}|null
	 */
	public static function range(): ?array {
		$range = self::record()['range'] ?? null;

		if ( ! is_array( $range ) || ! isset( $range['from'], $range['to'] ) ) {
			return null;
		}

		return [
			'from'      => (string) $range['from'],
			'to'        => (string) $range['to'],
			'checkedAt' => (int) ( $range['checkedAt'] ?? 0 ),
		];
	}

	/**
	 * Remember the range, so the preview screen does not ask Google twice.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function rememberRange( string $from, string $to ): void {
		$record          = self::record();
		$record['range'] = [
			'from'      => $from,
			'to'        => $to,
			'checkedAt' => time(),
		];

		self::save( $record );
	}

	/**
	 * Forget everything about the connection.
	 *
	 * Deletes rather than empties, so that nothing is left in the database once
	 * somebody has disconnected. Imported analytics are untouched: they belong
	 * to the site now, not to the connection that fetched them.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The connection, described safely.
	 *
	 * Everything a screen, a REST response or a log line could want, and no
	 * token in any of it.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe(): array {
		$record = self::record();
		$tokens = self::tokens();
		$detail = self::propertyDetail();
		$range  = self::range();

		return [
			'connected'    => null !== $tokens,
			'provider'     => (string) ( $record['provider'] ?? '' ),
			'since'        => (int) ( $record['since'] ?? 0 ),
			'property'     => self::property(),
			'propertyName' => $detail['displayName'],
			'propertyUrl'  => $detail['url'],
			'timezone'     => $detail['timezone'],
			'range'        => $range,
		];
	}

	/**
	 * An access token that will still work, refreshing it if it will not.
	 *
	 * @param Ga4ProviderInterface $provider The provider that owns the grant.
	 *
	 * @throws Ga4Exception When there is no connection, or the grant is gone.
	 */
	public static function accessToken( Ga4ProviderInterface $provider ): string {
		$tokens = self::tokens();

		if ( null === $tokens ) {
			throw new Ga4Exception( Ga4Exception::AUTH, 'No Google connection is stored for this site.' );
		}

		if ( $tokens->isFresh() ) {
			return $tokens->accessToken;
		}

		// Deliberately not logged. An access token lasts an hour, so a long
		// import refreshes several times an evening; recording each one as a
		// warning would bury the lines that matter. The import log is where a
		// refresh belongs, next to the batch it happened during.
		$refreshed = $provider->refresh( $tokens );

		self::remember( $refreshed, $provider->id() );

		return $refreshed->accessToken;
	}
}
