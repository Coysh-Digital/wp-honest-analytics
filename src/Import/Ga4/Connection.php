<?php
/**
 * Connecting and disconnecting Google.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Support\Timezone;
use HonestAnalytics\Support\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The OAuth round trip, and everything a screen needs to describe it.
 *
 * Uses `admin-post.php` rather than a REST route, because an OAuth redirect
 * comes back as a browser navigation and belongs in wp-admin where it can show
 * a notice and land the person back on the screen they started from. A REST
 * route would answer it with JSON to an empty tab.
 *
 * Every state-changing action here checks a capability and a nonce, and the
 * OAuth `state` is generated here and validated on return - Google's redirect
 * carries no nonce of ours, so `state` is what stops somebody else's callback
 * attaching their account to this site.
 */
final class Connection {

	/** The screen to return to. */
	public const SCREEN = 'honest-analytics-import';

	/** Where the OAuth state lives between leaving and coming back. */
	private const STATE_TRANSIENT = 'honest_analytics_ga4_state_';

	/** Fifteen minutes is long enough to sign in and short enough to be safe. */
	private const STATE_TTL = 900;

	/**
	 * Attach the handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_honest_analytics_ga4_connect', [ self::class, 'handleConnect' ] );
		add_action( 'admin_post_honest_analytics_ga4_callback', [ self::class, 'handleCallback' ] );
		add_action( 'admin_post_honest_analytics_ga4_disconnect', [ self::class, 'handleDisconnect' ] );
		add_action( 'admin_post_honest_analytics_ga4_credentials', [ self::class, 'handleCredentials' ] );
		add_action( 'admin_post_honest_analytics_ga4_property', [ self::class, 'handleProperty' ] );
	}

	/**
	 * The provider in use.
	 *
	 * The broker when there is one, the site's own Google client otherwise, and
	 * filterable so a different arrangement can be dropped in without touching
	 * a gate.
	 */
	public static function provider(): Ga4ProviderInterface {
		$broker = new BrokerProvider();
		$chosen = $broker->isConfigured() ? $broker : new GoogleClientProvider();

		/**
		 * Filters the Google connection provider.
		 *
		 * @param Ga4ProviderInterface $provider The provider.
		 */
		$provider = apply_filters( 'honest_analytics_ga4_provider', $chosen );

		return $provider instanceof Ga4ProviderInterface ? $provider : $chosen;
	}

	/**
	 * Everything a screen needs, with no token in it.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$provider = self::provider();
		$store    = TokenStore::describe();

		return array_merge(
			$store,
			[
				'configured'    => $provider->isConfigured(),
				'providerId'    => $provider->id(),
				'providerName'  => $provider->label(),
				'hint'          => $provider->isConfigured() ? '' : $provider->configurationHint(),
				'hasOwnClient'  => ( new GoogleClientProvider() )->isConfigured(),
				'connectUrl'    => self::actionUrl( 'connect' ),
				'disconnectUrl' => self::actionUrl( 'disconnect' ),
				'redirectUri'   => self::redirectUri(),
			]
		);
	}

	/**
	 * How many properties are worth fetching the website address for.
	 *
	 * Each one costs two requests to the Admin API, so a list of forty would
	 * mean eighty round trips to draw a screen. Almost nobody has forty. Below
	 * this, everybody gets the address that makes the list readable; above it,
	 * the list falls back to account and property name and the address appears
	 * on the confirmation step instead, where there is only one to fetch.
	 */
	private const DETAIL_LIMIT = 15;

	/** Fifteen minutes: long enough to walk back a step, short enough to notice a new property. */
	private const LIST_TRANSIENT = 'honest_analytics_ga4_properties';

	/**
	 * The accounts and properties this connection can see.
	 *
	 * Grouped by account, because that is how somebody thinks about it: they
	 * know which client the site belongs to before they know which of the three
	 * properties under it is the live one.
	 *
	 * Never throws. A screen should not have to catch an API failure to draw
	 * itself, so a failure comes back as a sentence in `error` and an empty
	 * list.
	 *
	 * @param bool $refresh Skip the cache, for the "not there? try again" link.
	 *
	 * @return array{accounts:array<int,array{account:string,properties:array<int,array<string,string>>}>,error:string,detailed:bool}
	 */
	public static function accounts( bool $refresh = false ): array {
		if ( ! TokenStore::isConnected() ) {
			return [
				'accounts' => [],
				'error'    => '',
				'detailed' => false,
			];
		}

		if ( ! $refresh ) {
			$cached = get_transient( self::LIST_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$client     = new Client( self::provider() );
			$properties = $client->properties();
			$detailed   = count( $properties ) <= self::DETAIL_LIMIT;
			$grouped    = [];

			foreach ( $properties as $property ) {
				if ( $detailed ) {
					try {
						$property = $client->propertyDetail( $property->name );
					} catch ( Ga4Exception $e ) {
						// One property that will not describe itself must not
						// hide the others. It keeps its name and loses only its
						// website address.
						unset( $e );
					}
				}

				$account = '' !== $property->account ? $property->account : __( 'Google Analytics', 'honest-analytics' );

				if ( ! isset( $grouped[ $account ] ) ) {
					$grouped[ $account ] = [
						'account'    => $account,
						'properties' => [],
					];
				}

				$grouped[ $account ]['properties'][] = [
					'name'        => $property->name,
					'id'          => $property->id(),
					'displayName' => $property->displayName,
					'url'         => $property->url,
					'timezone'    => $property->timezone,
				];
			}

			$result = [
				'accounts' => array_values( $grouped ),
				'error'    => '',
				'detailed' => $detailed,
			];

			set_transient( self::LIST_TRANSIENT, $result, 15 * MINUTE_IN_SECONDS );

			return $result;
		} catch ( Ga4Exception $e ) {
			Log::warning( 'Could not list Google Analytics properties: ' . $e->getMessage() );

			return [
				'accounts' => [],
				'error'    => $e->friendly(),
				'detailed' => false,
			];
		}
	}

	/**
	 * What a GA4 import job needs to know, for ImportConfiguration::$options.
	 *
	 * The id is what the API needs. The name is what the details screen shows
	 * somebody six months later, when "properties/123456789" would tell them
	 * nothing. The timezone is what lets the preview warn about a difference
	 * before it becomes four years of history sitting a day out.
	 *
	 * @return array<string,string>
	 */
	public static function importOptions(): array {
		$detail = TokenStore::propertyDetail();

		return [
			'property'     => TokenStore::property(),
			'propertyName' => '' !== $detail['displayName'] ? $detail['displayName'] : TokenStore::property(),
			'propertyUrl'  => $detail['url'],
			'timezone'     => $detail['timezone'],
		];
	}

	/**
	 * The two timezones, when they disagree.
	 *
	 * A property reporting in one timezone and a site working in another is the
	 * one silent way an import can end up a day out from top to bottom. There
	 * is nothing to fix - a daily total cannot be re-cut into another day - so
	 * the honest thing is to say so on the preview and let somebody decide
	 * whether they mind.
	 *
	 * @return array{property:string,site:string}|null
	 */
	public static function timezoneMismatch(): ?array {
		$property = (string) TokenStore::propertyDetail()['timezone'];
		$site     = Timezone::site()->getName();

		if ( '' === $property || $property === $site ) {
			return null;
		}

		return [
			'property' => $property,
			'site'     => $site,
		];
	}

	/**
	 * Forget the cached property list.
	 */
	public static function forgetProperties(): void {
		delete_transient( self::LIST_TRANSIENT );
	}

	/**
	 * A nonce-protected admin-post URL for one of the actions.
	 *
	 * @param string $action connect, disconnect, credentials or property.
	 */
	public static function actionUrl( string $action ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=honest_analytics_ga4_' . $action ),
			'honest-analytics-ga4-' . $action
		);
	}

	/**
	 * Where Google sends people back to.
	 *
	 * Fixed and registered with the OAuth client, so it carries no nonce - the
	 * `state` value is what proves the round trip belongs to us.
	 */
	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=honest_analytics_ga4_callback' );
	}

	/**
	 * Start the round trip.
	 */
	public static function handleConnect(): void {
		self::authorise( 'connect' );

		$provider = self::provider();

		if ( ! $provider->isConfigured() ) {
			self::back( 'not-configured' );
		}

		$state = wp_generate_password( 32, false );

		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, self::STATE_TTL );

		self::leaveForProvider( $provider->authorizationUrl( $state, self::redirectUri() ) );
	}

	/**
	 * Hand the browser to the provider's consent screen.
	 *
	 * `wp_safe_redirect()` refuses any host but this site's, which is exactly
	 * right for every other redirect in this file and exactly wrong for this
	 * one: the whole point of the step is to leave. Sending it through
	 * unmodified is what made Connect land back on the dashboard with nothing
	 * to show for it.
	 *
	 * So the guard stays and the destination is allowed for this one response.
	 * Anything that is not an HTTPS URL, or that turns out to point somewhere
	 * other than where the provider said, still bounces rather than redirecting
	 * a person somewhere unexpected.
	 *
	 * @param string $url Absolute HTTPS URL at the provider.
	 */
	private static function leaveForProvider( string $url ): void {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || '' === $host ) {
			Log::error( 'The Google Analytics provider returned an authorisation URL that was not an HTTPS address.' );

			self::back( 'failed' );
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = $host;

				return $hosts;
			}
		);

		wp_safe_redirect( $url, 302, 'Honest Analytics' );

		exit;
	}

	/**
	 * Come back from Google.
	 */
	public static function handleCallback(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to connect an analytics account.', 'honest-analytics' ), '', [ 'response' => 403 ] );
		}

		$key      = self::STATE_TRANSIENT . get_current_user_id();
		$expected = (string) get_transient( $key );

		delete_transient( $key );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Google's redirect carries no nonce of ours; the OAuth state below is what authenticates this round trip, and it is compared in constant time.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			// `access_denied` is somebody pressing Cancel, which is not a fault.
			self::back( 'access_denied' === $error ? 'cancelled' : 'failed' );
		}

		if ( '' === $expected || '' === $state || ! hash_equals( $expected, $state ) ) {
			Log::warning( 'A Google Analytics callback arrived with a state that did not match.' );

			self::back( 'state' );
		}

		if ( '' === $code ) {
			self::back( 'failed' );
		}

		try {
			$provider = self::provider();
			$tokens   = $provider->exchangeCode( $code, self::redirectUri() );

			TokenStore::remember( $tokens, $provider->id() );
		} catch ( Ga4Exception $e ) {
			Log::error( 'Could not complete the Google Analytics connection: ' . $e->getMessage() );

			match ( $e->reason ) {
				Ga4Exception::REASON_REDIRECT_MISMATCH => self::back( 'failed-redirect-mismatch' ),
				Ga4Exception::REASON_INVALID_CLIENT => self::back( 'failed-invalid-client' ),
				default => self::back( 'failed' ),
			};
		}

		self::back( 'connected' );
	}

	/**
	 * Disconnect, and tell Google we are finished with the grant.
	 */
	public static function handleDisconnect(): void {
		self::authorise( 'disconnect' );

		$tokens = TokenStore::tokens();

		if ( null !== $tokens ) {
			// The local connection goes whatever Google says. Somebody who has
			// pressed disconnect should not be left connected because a request
			// timed out.
			self::provider()->revoke( $tokens );
		}

		TokenStore::forget();
		self::forgetProperties();

		self::back( 'disconnected' );
	}

	/**
	 * Save a Google Cloud client id and secret.
	 */
	public static function handleCredentials(): void {
		self::authorise( 'credentials' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() has just checked the nonce for this action.
		$id     = isset( $_POST['honest_ga4_client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['honest_ga4_client_id'] ) ) : '';
		$secret = isset( $_POST['honest_ga4_client_secret'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['honest_ga4_client_secret'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		GoogleClientProvider::saveCredentials( $id, $secret );

		self::back( '' !== $id ? 'credentials-saved' : 'credentials-cleared' );
	}

	/**
	 * Choose which property to import from.
	 */
	public static function handleProperty(): void {
		self::authorise( 'property' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() has just checked the nonce for this action.
		$name = isset( $_POST['honest_ga4_property'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['honest_ga4_property'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 1 !== preg_match( '#^properties/[0-9]+$#', $name ) ) {
			self::back( 'property-invalid' );
		}

		try {
			$client   = new Client( self::provider() );
			$property = $client->propertyDetail( $name );

			TokenStore::chooseProperty( $property );
		} catch ( Ga4Exception $e ) {
			Log::error( 'Could not read the chosen Google Analytics property: ' . $e->getMessage() );

			match ( $e->reason ) {
				Ga4Exception::REASON_APIS_DISABLED_ADMIN => self::back( 'property-failed-apis-disabled-admin' ),
				default => self::back( 'property-failed' ),
			};
		}

		self::back( 'property-chosen' );
	}

	/**
	 * Capability and nonce, for an action somebody deliberately took.
	 *
	 * @param string $action The action suffix.
	 */
	private static function authorise( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to change the analytics connection.', 'honest-analytics' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'honest-analytics-ga4-' . $action );
	}

	/**
	 * Back to the import screen, with a word about what happened.
	 *
	 * Back to the Google step of it, not the front of the wizard: somebody who
	 * has just been bounced out of a connection attempt wants to see the thing
	 * they were doing, with the reason written next to it.
	 *
	 * @param string $result A short slug the screen turns into a sentence.
	 */
	private static function back( string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'   => self::SCREEN,
					'step'   => 'connect',
					'source' => 'ga4',
					'ga4'    => $result,
				],
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * What one of those slugs means, in a sentence.
	 *
	 * Every one of these used to be a silent redirect. A person who pressed
	 * Connect and landed back where they started with nothing written anywhere
	 * has no way to tell a cancelled sign-in from a broken configuration, and
	 * will reasonably conclude the plugin is broken.
	 *
	 * @param string $result The `ga4` query value.
	 *
	 * @return array{message:string,problem:bool}|null
	 */
	public static function outcome( string $result ): ?array {
		switch ( $result ) {
			case 'connected':
				return [
					'message' => __( 'Connected to Google. Choose which property to bring across.', 'honest-analytics' ),
					'problem' => false,
				];

			case 'disconnected':
				return [
					'message' => __( 'Disconnected from Google. Anything already imported stays exactly where it is.', 'honest-analytics' ),
					'problem' => false,
				];

			case 'credentials-saved':
				return [
					'message' => __( 'Saved. You can connect to Google now.', 'honest-analytics' ),
					'problem' => false,
				];

			case 'credentials-cleared':
				return [
					'message' => __( 'Those details have been removed from this site.', 'honest-analytics' ),
					'problem' => false,
				];

			case 'property-chosen':
				return [
					'message' => __( 'Property selected. You can choose your dates next.', 'honest-analytics' ),
					'problem' => false,
				];

			case 'cancelled':
				return [
					'message' => __( 'Sign-in was cancelled, so nothing has changed. You can try again whenever you like.', 'honest-analytics' ),
					'problem' => false,
				];

			case 'not-configured':
				return [
					'message' => __( 'There are no Google sign-in details saved on this site yet, so there is nothing to connect with. Fill in the two boxes below first.', 'honest-analytics' ),
					'problem' => true,
				];

			case 'state':
				return [
					'message' => __( 'The reply from Google did not match the request that started it, so it was ignored. That usually means the attempt was left for a while, or was finished in a different browser or tab. Press Connect and go straight through.', 'honest-analytics' ),
					'problem' => true,
				];

			case 'failed':
				return [
					'message' => __( 'Google refused the connection and did not say why in a way this site could read. Check the redirect address on this screen matches the one in your Google project character for character, and that both Analytics APIs are switched on there. Nothing on this site has been changed.', 'honest-analytics' ),
					'problem' => true,
				];

			case 'failed-redirect-mismatch':
				return [
					'message' => Ga4Exception::reasonSentence( Ga4Exception::REASON_REDIRECT_MISMATCH ),
					'problem' => true,
				];

			case 'failed-invalid-client':
				return [
					'message' => Ga4Exception::reasonSentence( Ga4Exception::REASON_INVALID_CLIENT ),
					'problem' => true,
				];

			case 'property-invalid':
				return [
					'message' => __( 'That is not a property this account can see. Choose one from the list.', 'honest-analytics' ),
					'problem' => true,
				];

			case 'property-failed':
				return [
					'message' => __( 'The property list could not be fetched from Google.', 'honest-analytics' ),
					'problem' => true,
				];

			case 'property-failed-apis-disabled-admin':
				return [
					'message' => Ga4Exception::reasonSentence( Ga4Exception::REASON_APIS_DISABLED_ADMIN ),
					'problem' => true,
				];
		}

		return null;
	}
}
