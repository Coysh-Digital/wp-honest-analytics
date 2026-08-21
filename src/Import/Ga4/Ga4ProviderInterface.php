<?php
/**
 * How this site gets a Google token.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The OAuth half, kept swappable because the arrangement is not settled.
 *
 * Two implementations ship. A hosted broker, for when there is one to point at,
 * which keeps the client secret off every customer's server. And a direct flow
 * for people who would rather register their own Google Cloud client than
 * involve anybody else - for this plugin's audience that is a real preference
 * rather than an edge case, so it is a supported path and not a workaround.
 *
 * Neither ever puts a client secret or an access token where a browser can see
 * it. Everything here runs server-side.
 */
interface Ga4ProviderInterface {

	/**
	 * A stable identifier, for storage and for the log.
	 */
	public function id(): string;

	/**
	 * What to show as "connected through", in plain words.
	 */
	public function label(): string;

	/**
	 * Whether this provider has what it needs to start.
	 */
	public function isConfigured(): bool;

	/**
	 * A sentence explaining what is missing, when it is not configured.
	 */
	public function configurationHint(): string;

	/**
	 * Where to send somebody to sign in.
	 *
	 * @param string $state       The CSRF value to hand back on return.
	 * @param string $redirectUri Where Google should return to.
	 */
	public function authorizationUrl( string $state, string $redirectUri ): string;

	/**
	 * Turn the code Google returned into tokens.
	 *
	 * @param string $code        The authorisation code.
	 * @param string $redirectUri The same URI used to obtain it.
	 *
	 * @throws Ga4Exception When the exchange fails.
	 */
	public function exchangeCode( string $code, string $redirectUri ): Ga4Tokens;

	/**
	 * Get a fresh access token.
	 *
	 * @param Ga4Tokens $tokens What is currently held.
	 *
	 * @throws Ga4Exception When the grant is gone.
	 */
	public function refresh( Ga4Tokens $tokens ): Ga4Tokens;

	/**
	 * Tell Google the site is finished with the grant.
	 *
	 * @param Ga4Tokens $tokens What is currently held.
	 */
	public function revoke( Ga4Tokens $tokens ): bool;
}
