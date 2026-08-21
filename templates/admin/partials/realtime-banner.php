<?php
/**
 * The live visitor banner.
 *
 * @package HonestAnalytics
 *
 * @var int    $visitors
 * @var string $url
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="ha-realtime-banner" href="<?php echo esc_url( $url ); ?>">
	<span class="ha-live-dot" aria-hidden="true"></span>

	<span class="ha-muted">
		<?php
		printf(
			/* translators: %s: number of visitors, already formatted. */
			esc_html( _n( '%s visitor on the site right now', '%s visitors on the site right now', $visitors, 'honest-analytics' ) ),
			'<strong>' . esc_html( number_format_i18n( $visitors ) ) . '</strong>'
		);
		?>
	</span>

	<span class="ha-realtime-go"><?php esc_html_e( 'Real-time →', 'honest-analytics' ); ?></span>
</a>
