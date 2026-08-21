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

	/**
	 * @param string $kind       One of the constants above.
	 * @param string $message    Technical detail, for the log.
	 * @param int    $retryAfter Seconds Google asked for, where it said.
	 */
	public function __construct(
		public readonly string $kind,
		string $message,
		public readonly int $retryAfter = 0
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

		return __( 'We could not continue the Google Analytics import. Nothing already imported has been changed.', 'honest-analytics' );
	}

	/**
	 * Whether waiting is likely to help.
	 */
	public function isRecoverable(): bool {
		return self::RATE_LIMIT === $this->kind || self::TRANSIENT === $this->kind;
	}
}
