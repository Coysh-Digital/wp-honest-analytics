<?php
/**
 * A restrained placeholder where a Pro card would be.
 *
 * A gap where a card should be reads as a bug rather than as an edition
 * boundary, so the card stays and says in one sentence what it would tell you.
 * No upgrade button, no price, no colour: this is a note, not an advertisement.
 *
 * The title links to the report's own page, where the same thing is said at
 * length. One link on a card somebody is already looking at is discovery; a
 * button would be a sales pitch.
 *
 * @package HonestAnalytics
 *
 * @var string $title
 * @var string $description
 * @var string $slug        Optional. Screen slug to link the title at.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_slug = (string) ( $slug ?? '' );
$ha_url  = '' !== $ha_slug
	? add_query_arg( [ 'page' => $ha_slug ], admin_url( 'admin.php' ) )
	: '';
?>
<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title">
			<?php if ( '' !== $ha_url ) : ?>
				<a href="<?php echo esc_url( $ha_url ); ?>"><?php echo esc_html( $title ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $title ); ?>
			<?php endif; ?>
		</h2>
		<span class="ha-badge is-pro"><?php esc_html_e( 'Pro', 'honest-analytics' ); ?></span>
	</div>

	<div class="ha-card-body">
		<div class="ha-placeholder-bars" aria-hidden="true">
			<div class="ha-placeholder-row"><div class="ha-placeholder-bar"></div></div>
			<div class="ha-placeholder-row"><div class="ha-placeholder-bar"></div></div>
			<div class="ha-placeholder-row"><div class="ha-placeholder-bar"></div></div>
		</div>

		<p class="ha-muted"><?php echo esc_html( $description ); ?></p>
	</div>
</div>
