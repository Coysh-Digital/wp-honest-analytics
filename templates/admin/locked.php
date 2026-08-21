<?php
/**
 * What a Pro report would show, on a site that does not have it.
 *
 * No figures, real or invented. The skeleton is a shape, marked hidden from
 * assistive technology because it says nothing; everything that carries meaning
 * is written out in words underneath it.
 *
 * @package HonestAnalytics
 *
 * @var string   $title
 * @var string   $lede
 * @var string[] $shows
 * @var string   $note
 * @var string   $homeUrl
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
		<p class="ha-lede"><?php echo esc_html( $lede ); ?></p>

		<div class="ha-placeholder-bars" aria-hidden="true">
			<div class="ha-placeholder-row"><div class="ha-placeholder-bar"></div></div>
			<div class="ha-placeholder-row"><div class="ha-placeholder-bar"></div></div>
			<div class="ha-placeholder-row"><div class="ha-placeholder-bar"></div></div>
		</div>

		<h3 class="ha-subhead"><?php esc_html_e( 'What this report shows', 'honest-analytics' ); ?></h3>

		<ul class="ha-plain-list">
			<?php foreach ( $shows as $ha_line ) : ?>
				<li><?php echo esc_html( $ha_line ); ?></li>
			<?php endforeach; ?>
		</ul>

		<?php if ( '' !== $note ) : ?>
			<p class="ha-muted ha-measure ha-gap-above"><?php echo esc_html( $note ); ?></p>
		<?php endif; ?>
	</div>
</div>

<div class="ha-info">
	<div>
		<p>
			<strong><?php esc_html_e( 'Nothing on this page is switched off.', 'honest-analytics' ); ?></strong>
			<?php esc_html_e( 'The free edition does not contain this report at all - it is removed when the plugin is packaged, rather than hidden behind a check. That is why there is no key to enter here and nothing to unlock.', 'honest-analytics' ); ?>
		</p>

		<p>
			<?php
			printf(
				/* translators: %s: a link, reading "Honest Analytics Pro". */
				esc_html__( 'The paid edition is a separate download that reads and writes the same tables as this one, so moving between them migrates nothing and loses nothing. %s.', 'honest-analytics' ),
				'<a href="' . esc_url( $homeUrl ) . '" rel="noopener noreferrer" target="_blank">'
					. esc_html__( 'Honest Analytics Pro', 'honest-analytics' )
					. '</a>'
			);
			?>
		</p>
	</div>
</div>

<p class="ha-footnote">
	<?php esc_html_e( 'Everything the free edition does measure keeps working exactly as it does now, whatever you decide. There is no trial, nothing expires, and this page will not ask you again.', 'honest-analytics' ); ?>
</p>
