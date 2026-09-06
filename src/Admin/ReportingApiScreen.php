<?php
/**
 * The reporting API connection screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin;

use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Rest\ReportingApiAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small settings screen for the read-only reporting API: paste the connection
 * code from an external reporting tool and save. Deliberately self-contained -
 * one shared secret in one option - so connecting a tool is copy, paste, done.
 * Blank disables the API.
 */
final class ReportingApiScreen {

	private const SLUG = 'honest-analytics-reporting';

	private const OPTION_GROUP = 'honest_analytics_reporting';

	/**
	 * Hook the submenu and register the stored option.
	 */
	public static function register(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page(
					Menu::SLUG,
					__( 'Reporting API', 'honest-analytics' ),
					__( 'Reporting API', 'honest-analytics' ),
					Capabilities::MANAGE,
					self::SLUG,
					[ self::class, 'render' ]
				);
			},
			// After Menu::register(), so the parent exists.
			20
		);

		add_action(
			'admin_init',
			static function (): void {
				register_setting(
					self::OPTION_GROUP,
					ReportingApiAuth::SECRET_OPTION,
					[
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					]
				);
			}
		);

		// options.php checks manage_options unless told otherwise, so without
		// this the screen would render for somebody holding the plugin's own
		// manage capability and then refuse to save what they typed. The two
		// are not far apart - Capabilities::mapMetaCap() falls back to
		// manage_options - but "not far apart" is how a form silently stops
		// working for one site in a hundred.
		add_filter(
			'option_page_capability_' . self::OPTION_GROUP,
			static fn (): string => Capabilities::MANAGE
		);
	}

	/**
	 * Render the connection screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$secret = ReportingApiAuth::secret();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reporting API', 'honest-analytics' ); ?></h1>

			<p><?php esc_html_e( 'Let an external reporting tool pull this site’s aggregated stats over a signed, read-only request. Paste the connection code from that tool below and save. Leave it blank to keep the API off.', 'honest-analytics' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="honest-analytics-reporting-code"><?php esc_html_e( 'Connection code', 'honest-analytics' ); ?></label></th>
						<td>
							<input id="honest-analytics-reporting-code" type="text" class="regular-text code ha-copyable"
								name="<?php echo esc_attr( ReportingApiAuth::SECRET_OPTION ); ?>"
								value="<?php echo esc_attr( $secret ); ?>"
								autocomplete="off">
							<p class="description"><?php esc_html_e( 'Keep this secret. Anyone with it can read this site’s aggregated stats.', 'honest-analytics' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
