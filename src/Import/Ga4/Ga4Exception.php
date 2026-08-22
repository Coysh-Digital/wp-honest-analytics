<?php
/**
 * Something went wrong talking to Google.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One exception, with a kind, because the caller's decision depends on which.
 *
 * A rate limit is not a failure - it is the normal experience of reading four
 * years out of an API - and the importer answers it by waiting rather than by
 * giving up. An expired grant is a failure the user has to fix. A network
 * hiccup is worth retrying. Collapsing all three into "error" would make the
 * import either too fragile or too stubborn.
 */
final class Ga4Exception extends \RuntimeException {

	/** Google asked us to slow down. Wait and carry on. */
	public const RATE_LIMIT = 'rate_limit';

	/** The connection is no longer valid. The user has to reconnect. */
	public const AUTH = 'auth';

	/** A timeout, a 500, a dropped connection. Worth another go shortly. */
	public const TRANSIENT = 'transient';

	/** A bad request, a missing property, a permission the account lacks. */
	public const FATAL = 'fatal';

	/** The redirect URI Google was given does not match what is registered. */
	public const REASON_REDIRECT_MISMATCH = 'redirect-mismatch';

	/** Google does not recognise the Client ID and/or secret saved here. */
	public const REASON_INVALID_CLIENT = 'invalid-client';

	/** The Google Analytics Admin API is not switched on in the Cloud project. */
	public const REASON_APIS_DISABLED_ADMIN = 'apis-disabled-admin';

	/** The Google Analytics Data API is not switched on in the Cloud project. */
	public const REASON_APIS_DISABLED_DATA = 'apis-disabled-data';

	/** One of the two APIs is off, but Google did not say which. */
	public const REASON_APIS_DISABLED = 'apis-disabled';

	/**
	 * @param string $kind       One of the constants above.
	 * @param string $message    Technical detail, for the log.
	 * @param int    $retryAfter Seconds Google asked for, where it said.
	 * @param string $reason     One of the REASON_* constants, where the FATAL kind is specific enough to name.
	 */
	public function __construct(
		public readonly string $kind,
		string $message,
		public readonly int $retryAfter = 0,
		public readonly string $reason = ''
	) {
		parent::__construct( $message );
	}

	/**
	 * A sentence for a person, with no status codes in it.
	 */
	public function friendly(): string {
		switch ( $this->kind ) {
			case self::RATE_LIMIT:
				return __( 'Google temporarily limited how quickly we can read your data. Your progress has been saved and we will carry on shortly.', 'honest-analytics' );

			case self::AUTH:
				return __( 'The connection to Google has expired. Reconnect your Google account and the import will pick up where it stopped.', 'honest-analytics' );

			case self::TRANSIENT:
				return __( 'We could not reach Google just then. Nothing has been lost and we will try again in a moment.', 'honest-analytics' );
		}

		return self::reasonSentence( $this->reason );
	}

	/**
	 * What a REASON_* constant means, in a sentence.
	 *
	 * The one place this wording lives. A FATAL exception still holding the
	 * exception object reaches it through friendly(); a redirect-based caller
	 * that only has the `reason` slug left after the round trip calls this
	 * directly from Connection::outcome() - both say exactly the same thing.
	 *
	 * @param string $reason One of the REASON_* constants, or '' for none.
	 */
	public static function reasonSentence( string $reason ): string {
		switch ( $reason ) {
			case self::REASON_REDIRECT_MISMATCH:
				return __( 'Google says the redirect address does not match what is registered for this project. Open the OAuth client in Google Cloud and check the Authorised redirect URI is copied from this screen character for character - including whether it is http or https, and whether it has www. Nothing on this site has been changed.', 'honest-analytics' );

			case self::REASON_INVALID_CLIENT:
				return __( 'Google did not recognise the Client ID and secret saved on this site. Check they were copied in full from Google Cloud - a client secret is long, and a partial paste is easy to miss - and save them again below. Nothing on this site has been changed.', 'honest-analytics' );

			case self::REASON_APIS_DISABLED_ADMIN:
				return __( 'The Google Analytics Admin API is not switched on in your Google Cloud project yet. Enable it, then try again. Nothing on this site has been changed.', 'honest-analytics' );

			case self::REASON_APIS_DISABLED_DATA:
				return __( 'The Google Analytics Data API is not switched on in your Google Cloud project yet. Enable it, then try again. Nothing on this site has been changed.', 'honest-analytics' );

			case self::REASON_APIS_DISABLED:
				return __( 'One of the two Google Analytics APIs is not switched on in your Google Cloud project yet. Check both are enabled, then try again. Nothing on this site has been changed.', 'honest-analytics' );
		}

		return __( 'We could not continue the Google Analytics import. Nothing already imported has been changed.', 'honest-analytics' );
	}

	/**
	 * Whether waiting is likely to help.
	 */
	public function isRecoverable(): bool {
		return self::RATE_LIMIT === $this->kind || self::TRANSIENT === $this->kind;
	}
}
