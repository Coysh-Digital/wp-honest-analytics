<?php
/**
 * The first-run setup wizard.
 *
 * Deliberately short: a handful of settings, plain language, and a way out that
 * saves nothing. Everything here also lives on the Settings screen, so nobody is
 * cornered - the copy says so rather than implying a decision has to be made
 * now.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Settings\Settings $settings
 * @var string                             $nonce
 * @var string                             $action Where the form posts back to.
 * @var string                             $docs   Documentation base URL.
 */

declare(strict_types=1);

use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_months  = (int) $settings->rollupRetentionMonths;
$ha_choices = [ 6, 12, 24, 36 ];

// Keep whatever is already stored selectable, even if it is not one of the
// friendly presets - a wizard that silently changed a value the moment it drew
// itself would be worse than no wizard.
if ( ! in_array( $ha_months, $ha_choices, true ) ) {
	$ha_choices[] = $ha_months;
	sort( $ha_choices );
}
?>

<div class="ha-setup">
	<p class="ha-setup-lede">
		<?php esc_html_e( 'Honest Analytics is already recording visits for this site, with privacy-preserving defaults. Set a few things people ask about most, or skip this - you can change any of it later under Settings.', 'honest-analytics' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<?php wp_nonce_field( $nonce ); ?>

		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'How visits are counted', 'honest-analytics' ); ?></h2>
			</div>

			<div class="ha-settings-body">
				<div class="ha-setting">
					<label for="ha-setup-trackingMode"><?php esc_html_e( 'Counting method', 'honest-analytics' ); ?></label>

					<div>
						<div class="ha-setting-control">
							<select id="ha-setup-trackingMode" name="trackingMode">
								<?php
								foreach (
									[
										Settings::TRACKING_HYBRID => __( 'Hybrid (recommended)', 'honest-analytics' ),
										Settings::TRACKING_SERVER => __( 'Server-side only', 'honest-analytics' ),
										Settings::TRACKING_CLIENT => __( 'Browser beacon only', 'honest-analytics' ),
									] as $ha_value => $ha_label
								) :
									?>
									<option value="<?php echo esc_attr( $ha_value ); ?>" <?php selected( $settings->trackingMode, $ha_value ); ?>>
										<?php echo esc_html( $ha_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<p class="ha-setting-help">
							<?php esc_html_e( 'Hybrid counts each view in WordPress and confirms it from the browser, so it stays accurate whether or not a page cache is in front of your site. Most sites should leave this on hybrid.', 'honest-analytics' ); ?>
						</p>
					</div>
				</div>

				<div class="ha-setting">
					<span class="ha-setting-label"><?php esc_html_e( 'Who counts as a visitor', 'honest-analytics' ); ?></span>

					<div>
						<div class="ha-setting-control">
							<label>
								<input type="checkbox" name="excludeLoggedIn" value="1" <?php checked( $settings->excludeLoggedIn ); ?> />
								<?php esc_html_e( 'Do not count signed-in editors, authors and administrators', 'honest-analytics' ); ?>
							</label>
						</div>

						<div class="ha-setting-control ha-gap-above">
							<label>
								<input type="checkbox" name="blockCrawlers" value="1" <?php checked( $settings->blockCrawlers ); ?> />
								<?php esc_html_e( 'Keep known bots and crawlers out of the counts', 'honest-analytics' ); ?>
							</label>
						</div>

						<p class="ha-setting-help">
							<?php esc_html_e( 'Your own visits while logged in can skew a small site, and search-engine and monitoring bots announce themselves. Leaving both on keeps your numbers reflecting real people. Subscribers and customers are still counted, because they are visitors.', 'honest-analytics' ); ?>
						</p>
					</div>
				</div>

				<div class="ha-setting">
					<span class="ha-setting-label"><?php esc_html_e( 'Tidier page addresses', 'honest-analytics' ); ?></span>

					<div>
						<div class="ha-setting-control">
							<label>
								<input type="checkbox" name="stripQueryString" value="1" <?php checked( $settings->stripQueryString ); ?> />
								<?php esc_html_e( 'Ignore query parameters like utm_source in page addresses', 'honest-analytics' ); ?>
							</label>
						</div>

						<p class="ha-setting-help">
							<?php esc_html_e( 'Records /page?utm_source=news as /page, so campaign tags do not split one page into many in your reports. Leave it off if query parameters mark genuinely different pages on your site.', 'honest-analytics' ); ?>
						</p>

						<p class="ha-setting-help">
							<a href="<?php echo esc_url( $docs ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Learn more about how visits are counted', 'honest-analytics' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'Your data', 'honest-analytics' ); ?></h2>
			</div>

			<div class="ha-settings-body">
				<div class="ha-setting">
					<label for="ha-setup-rollupRetentionMonths"><?php esc_html_e( 'Keep reports for', 'honest-analytics' ); ?></label>

					<div>
						<div class="ha-setting-control">
							<select id="ha-setup-rollupRetentionMonths" name="rollupRetentionMonths">
								<?php foreach ( $ha_choices as $ha_choice ) : ?>
									<option value="<?php echo esc_attr( (string) $ha_choice ); ?>" <?php selected( $ha_months, $ha_choice ); ?>>
										<?php
										echo esc_html(
											Settings::ROLLUP_MAX_RETENTION_MONTHS === $ha_choice
												? sprintf( /* translators: %d: number of months. */ __( '%d months (the longest we keep)', 'honest-analytics' ), $ha_choice )
												: sprintf( /* translators: %d: number of months. */ __( '%d months', 'honest-analytics' ), $ha_choice )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<p class="ha-setting-help">
							<?php esc_html_e( 'Honest Analytics stores daily totals, never a log of individual visits. Older totals are tidied away automatically once they pass the window you choose here.', 'honest-analytics' ); ?>
						</p>
					</div>
				</div>

				<div class="ha-setting">
					<span class="ha-setting-label"><?php esc_html_e( 'If the plugin is removed', 'honest-analytics' ); ?></span>

					<div>
						<div class="ha-setting-control">
							<label>
								<input type="checkbox" name="keepDataOnUninstall" value="1" <?php checked( $settings->keepDataOnUninstall ); ?> />
								<?php esc_html_e( 'Keep my analytics history if the plugin is ever deleted', 'honest-analytics' ); ?>
							</label>
						</div>

						<p class="ha-setting-help">
							<?php esc_html_e( 'Your reports live in your own database. Leave this on to keep them through an uninstall and reinstall; turn it off to have everything removed when the plugin is deleted. Deactivating never deletes anything either way.', 'honest-analytics' ); ?>
						</p>

						<p class="ha-setting-help">
							<a href="<?php echo esc_url( $docs ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Learn more about data retention', 'honest-analytics' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head">
				<h2 class="ha-card-title"><?php esc_html_e( 'Respect visitors’ privacy signals', 'honest-analytics' ); ?></h2>
			</div>

			<div class="ha-settings-body">
				<div class="ha-setting">
					<span class="ha-setting-label"><?php esc_html_e( 'Browser signals', 'honest-analytics' ); ?></span>

					<div>
						<div class="ha-setting-control">
							<label>
								<input type="checkbox" name="honourGpc" value="1" <?php checked( $settings->honourGpc ); ?> />
								<?php esc_html_e( 'Honour Global Privacy Control (recommended)', 'honest-analytics' ); ?>
							</label>
						</div>

						<div class="ha-setting-control ha-gap-above">
							<label>
								<input type="checkbox" name="honourDnt" value="1" <?php checked( $settings->honourDnt ); ?> />
								<?php esc_html_e( 'Honour Do Not Track', 'honest-analytics' ); ?>
							</label>
						</div>

						<p class="ha-setting-help">
							<?php esc_html_e( 'When a visitor’s browser asks not to be tracked, Honest Analytics can leave them out of the counts entirely. Global Privacy Control is the newer, legally recognised signal; Do Not Track is older and widely ignored, which is why it is off by default.', 'honest-analytics' ); ?>
						</p>

						<p class="ha-setting-help">
							<a href="<?php echo esc_url( $docs ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Learn more about privacy signals', 'honest-analytics' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="ha-setup-actions">
			<button type="submit" name="ha_setup_action" value="finish" class="button button-primary button-hero">
				<?php esc_html_e( 'Save and finish', 'honest-analytics' ); ?>
			</button>

			<button type="submit" name="ha_setup_action" value="skip" class="button-link ha-setup-skip">
				<?php esc_html_e( 'Skip for now', 'honest-analytics' ); ?>
			</button>
		</div>
	</form>
</div>
