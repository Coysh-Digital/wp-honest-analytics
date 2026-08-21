<?php
/**
 * Installing the geo database from the admin.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Geo;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Locations screen's install form.
 *
 * The database has always been installable with WP-CLI, which is fine for
 * anybody who has a terminal and no help at all to anybody who does not. Plenty
 * of WordPress sites are administered entirely through the browser, so the same
 * three operations - install a file, fetch a URL, remove what is there - are
 * here as an ordinary admin-post form.
 *
 * The uploaded file never goes through the media library. It is read straight
 * out of the PHP upload temp file and written to the plugin's own directory, so
 * a country database does not end up as an attachment somebody can stumble into
 * or, worse, request over HTTP.
 */
final class GeoHandler {

	public const ACTION = 'honest_analytics_geo';
	public const NONCE  = 'honest-analytics-geo';

	/**
	 * Where the result of the last attempt is left for the redirect.
	 */
	private const NOTICE = 'honest_analytics_geo_notice';

	/**
	 * Attach the handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * The form action.
	 */
	public static function url(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Take the result of the last attempt, once.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function takeNotice(): ?array {
		$notice = get_transient( self::NOTICE . '_' . get_current_user_id() );

		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return null;
		}

		delete_transient( self::NOTICE . '_' . get_current_user_id() );

		return [
			'type'    => (string) $notice['type'],
			'message' => (string) $notice['message'],
		];
	}

	/**
	 * Install, fetch or remove.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change the geo database for this site.', 'honest-analytics' ),
				esc_html__( 'Not allowed', 'honest-analytics' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately above.
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( (string) $_POST['task'] ) ) : '';

		switch ( $task ) {
			case 'upload':
				self::install( self::fromUpload() );
				break;

			case 'url':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
				$url = isset( $_POST['geo_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['geo_url'] ) ) : '';

				if ( '' === $url ) {
					self::remember( 'error', __( 'Paste the address of a .mmdb file first.', 'honest-analytics' ) );
					break;
				}

				self::install( ( new GeoInstaller() )->installFromUrl( $url ) );
				break;

			case 'remove':
				self::remove();
				break;

			default:
				self::remember( 'error', __( 'That is not something this form does.', 'honest-analytics' ) );
		}

		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * Read the uploaded file and install it.
	 *
	 * @return true|string True, or an error message.
	 */
	private static function fromUpload(): bool|string {
		// $_FILES is filled in by PHP from the multipart body, not by the
		// request, so there is no string here to sanitize: the two values read
		// are an integer status code and a temp path that is_uploaded_file()
		// then vouches for. The nonce was checked by the caller.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		$file = isset( $_FILES['geo_file'] ) && is_array( $_FILES['geo_file'] ) ? $_FILES['geo_file'] : null;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

		if ( null === $file || ! isset( $file['tmp_name'], $file['error'] ) ) {
			return __( 'Choose a file first.', 'honest-analytics' );
		}

		$error = (int) $file['error'];

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return __( 'Choose a file first.', 'honest-analytics' );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return sprintf(
				/* translators: %s: a file size, for example "8 MB". */
				__( 'That file is larger than this server accepts as an upload, which is %s. Use the address route below instead, which does not go through PHP\'s upload limit.', 'honest-analytics' ),
				size_format( wp_max_upload_size() )
			);
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return __( 'The upload did not finish. Try again.', 'honest-analytics' );
		}

		$path = (string) $file['tmp_name'];

		// The whole point of the check: only a file PHP itself received as an
		// upload, so a crafted path cannot be turned into an arbitrary read.
		if ( ! is_uploaded_file( $path ) ) {
			return __( 'That upload could not be verified.', 'honest-analytics' );
		}

		return ( new GeoInstaller() )->installFromFile( $path );
	}

	/**
	 * Record the outcome of an install.
	 *
	 * @param true|string $result From the installer.
	 */
	private static function install( bool|string $result ): void {
		if ( true !== $result ) {
			self::remember( 'error', is_string( $result ) ? $result : __( 'The database could not be installed.', 'honest-analytics' ) );

			return;
		}

		self::remember(
			'success',
			__( 'Database installed. Turn on Geography in Settings if it is not on already; countries are recorded from the next visit onwards, not backdated.', 'honest-analytics' )
		);
	}

	/**
	 * Delete the installed database.
	 */
	private static function remove(): void {
		$path = Paths::geoDatabase();

		if ( ! is_file( $path ) ) {
			self::remember( 'error', __( 'There is no database installed to remove.', 'honest-analytics' ) );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The failure is reported below rather than printed.
			self::remember( 'error', __( 'The file could not be deleted. Check the permissions on the uploads directory.', 'honest-analytics' ) );

			return;
		}

		self::remember(
			'success',
			__( 'Database removed. Country reporting stops here; the countries already counted stay where they are.', 'honest-analytics' )
		);
	}

	/**
	 * Leave a message for the screen the redirect lands on.
	 *
	 * Per user, and short-lived: it belongs to one round trip, not to the site.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message What to say.
	 */
	private static function remember( string $type, string $message ): void {
		set_transient(
			self::NOTICE . '_' . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Where to go afterwards.
	 */
	private static function back(): string {
		$referer = wp_get_referer();

		return false !== $referer
			? wp_validate_redirect( $referer, self::locations() )
			: self::locations();
	}

	/**
	 * The Locations screen.
	 */
	private static function locations(): string {
		return add_query_arg( [ 'page' => 'honest-analytics-locations' ], admin_url( 'admin.php' ) );
	}
}
