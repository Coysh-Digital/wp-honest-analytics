<?php
/**
 * A restrained placeholder where a Pro card would be.
 *
 * A gap where a card should be reads as a bug rather than as an edition
 * boundary, so the card stays and says in one sentence what it would tell you.
 * No upgrade button, no price, no colour: this is a note, not an advertisement.
 *
 * @package HonestAnalytics
 *
 * @var string $title
 * @var string $description
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php echo esc_html( $title ); ?></h2>
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
