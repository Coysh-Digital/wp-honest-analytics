<?php
/**
 * Connecting to Google Analytics, and choosing which property to read.
 *
 * Four states, in the order somebody meets them: nothing is set up, nothing is
 * connected, connected but no property chosen, and ready. Each says what it is
 * and offers the one thing to do next - a screen that shows all four at once
 * would be a configuration panel, and this is meant to be a wizard.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen    $screen
 * @var \HonestAnalytics\Import\ImporterInterface|null $importer
 * @var array<string,mixed>|null                       $connection
 */

declare(strict_types=1);

use HonestAnalytics\Import\Ga4\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_state = is_array( $connection ) ? $connection : [];
$ha_ready = ! empty( $ha_state['connected'] ) && '' !== (string) ( $ha_state['property'] ?? '' );
?>

<?php if ( [] === $ha_state ) : ?>

	<div class="ha-card">
		<div class="ha-card-body">
			<p><?php esc_html_e( 'Importing from Google Analytics is not available in this build.', 'honest-analytics' ); ?></p>
			<p class="ha-muted"><?php esc_html_e( 'The other import options on this screen still work.', 'honest-analytics' ); ?></p>
			<p class="ha-gap-above"><a class="button" href="<?php echo esc_url( $screen->stepUrl( 'sources' ) ); ?>"><?php esc_html_e( 'Back', 'honest-analytics' ); ?></a></p>
		</div>
	</div>

<?php elseif ( empty( $ha_state['configured'] ) ) : ?>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Connecting to Google', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<p class="ha-measure"><?php echo esc_html( (string) ( $ha_state['hint'] ?? '' ) ); ?></p>

			<p class="ha-muted ha-measure">
				<?php esc_html_e( 'You can also use your own Google Cloud project, which keeps the connection entirely between this site and Google. It takes a few minutes to set up and is described in the documentation.', 'honest-analytics' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ha-gap-above">
				<input type="hidden" name="action" value="honest_analytics_ga4_credentials" />
				<?php wp_nonce_field( 'honest-analytics-ga4-credentials' ); ?>

				<div class="ha-form-row">
					<label class="ha-field" for="honest-ga4-client-id">
						<?php esc_html_e( 'Client ID', 'honest-analytics' ); ?>
						<input type="text" id="honest-ga4-client-id" name="honest_ga4_client_id" class="regular-text" autocomplete="off" spellcheck="false" />
					</label>

					<label class="ha-field" for="honest-ga4-client-secret">
						<?php esc_html_e( 'Client secret', 'honest-analytics' ); ?>
						<input type="password" id="honest-ga4-client-secret" name="honest_ga4_client_secret" class="regular-text" autocomplete="off" />
					</label>

					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'honest-analytics' ); ?></button>
				</div>

				<p class="ha-muted ha-fine">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the redirect URI to paste into Google Cloud. */
							__( 'Add this as an authorised redirect URI in your Google Cloud project: %s', 'honest-analytics' ),
							(string) ( $ha_state['redirectUri'] ?? '' )
						)
					);
					?>
				</p>
			</form>
		</div>
	</div>

<?php elseif ( empty( $ha_state['connected'] ) ) : ?>

	<div class="ha-card">
		<div class="ha-card-body">
			<div class="ha-posture is-consent">
				<span class="ha-posture-icon" aria-hidden="true">!</span>

				<div>
					<div class="ha-posture-label"><?php esc_html_e( 'Google Analytics', 'honest-analytics' ); ?></div>
					<div class="ha-posture-title"><?php esc_html_e( 'Not connected yet', 'honest-analytics' ); ?></div>
					<p class="ha-posture-body">
						<?php esc_html_e( 'Sign in with the Google account that can see the property you want to bring across. Only permission to read your analytics is requested - nothing is ever written back to Google, and you can disconnect at any time.', 'honest-analytics' ); ?>
					</p>
				</div>
			</div>

			<p class="ha-gap-above">
				<a class="button button-primary" href="<?php echo esc_url( (string) ( $ha_state['connectUrl'] ?? '' ) ); ?>">
					<?php esc_html_e( 'Connect Google Analytics', 'honest-analytics' ); ?>
				</a>

				<a class="button-link ha-gap-above" href="<?php echo esc_url( $screen->stepUrl( 'sources' ) ); ?>"><?php esc_html_e( 'Cancel', 'honest-analytics' ); ?></a>
			</p>
		</div>
	</div>

<?php elseif ( ! $ha_ready ) : ?>

	<?php
	$ha_list     = $screen->accounts();
	$ha_accounts = $ha_list['accounts'];
	$ha_index    = 0;
	?>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Choose a property', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<?php if ( '' !== $ha_list['error'] ) : ?>
				<p><?php echo esc_html( $ha_list['error'] ); ?></p>
			<?php elseif ( [] === $ha_accounts ) : ?>
				<p><?php esc_html_e( 'This Google account cannot see any Analytics properties. Try connecting with a different account.', 'honest-analytics' ); ?></p>
			<?php else : ?>
				<p class="ha-muted ha-measure"><?php esc_html_e( 'Pick the property that measures this website.', 'honest-analytics' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ha-gap-above">
					<input type="hidden" name="action" value="honest_analytics_ga4_property" />
					<?php wp_nonce_field( 'honest-analytics-ga4-property' ); ?>

					<?php foreach ( $ha_accounts as $ha_account ) : ?>
						<?php $ha_properties = is_array( $ha_account['properties'] ?? null ) ? $ha_account['properties'] : []; ?>

						<?php if ( [] === $ha_properties ) : ?>
							<?php continue; ?>
						<?php endif; ?>

						<div class="ha-gap-above">
							<div class="ha-kpi-label"><?php echo esc_html( (string) ( $ha_account['account'] ?? '' ) ); ?></div>

							<div
								class="ha-choices"
								role="radiogroup"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: a Google Analytics account name. */ __( 'Properties in %s', 'honest-analytics' ), (string) ( $ha_account['account'] ?? '' ) ) ); ?>"
							>
								<?php foreach ( $ha_properties as $ha_property ) : ?>
									<?php
									++$ha_index;

									$ha_id     = 'honest-ga4-property-' . $ha_index;
									$ha_detail = (string) ( $ha_property['url'] ?? '' );

									if ( '' === $ha_detail ) {
										$ha_detail = sprintf(
											/* translators: %s: a numeric Google Analytics property id. */
											__( 'Property %s', 'honest-analytics' ),
											(string) ( $ha_property['id'] ?? '' )
										);
									}
									?>
									<label class="ha-choice" for="<?php echo esc_attr( $ha_id ); ?>">
										<input
											type="radio"
											id="<?php echo esc_attr( $ha_id ); ?>"
											name="honest_ga4_property"
											value="<?php echo esc_attr( (string) ( $ha_property['name'] ?? '' ) ); ?>"
											<?php checked( 1, $ha_index ); ?>
										/>

										<span>
											<strong><?php echo esc_html( (string) ( $ha_property['displayName'] ?? '' ) ); ?></strong>
											<span class="ha-choice-detail"><?php echo esc_html( $ha_detail ); ?></span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<p class="ha-gap-above">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'honest-analytics' ); ?></button>
					</p>
				</form>

				<?php if ( ! $ha_list['detailed'] ) : ?>
					<p class="ha-muted ha-fine">
						<?php esc_html_e( 'This account has a lot of properties, so their website addresses are not listed here - fetching each one would mean a great many requests to Google. The address of whichever you choose is shown on the next step.', 'honest-analytics' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<p class="ha-muted ha-fine">
				<a href="<?php echo esc_url( (string) ( $ha_state['disconnectUrl'] ?? '' ) ); ?>"><?php esc_html_e( 'Use a different Google account', 'honest-analytics' ); ?></a>
			</p>
		</div>
	</div>

<?php else : ?>

	<div class="ha-card">
		<div class="ha-card-body">
			<div class="ha-posture is-clean">
				<span class="ha-posture-icon" aria-hidden="true">&#10003;</span>

				<div>
					<div class="ha-posture-label"><?php esc_html_e( 'Connected', 'honest-analytics' ); ?></div>
					<div class="ha-posture-title"><?php echo esc_html( (string) ( $ha_state['propertyName'] ?? '' ) ); ?></div>
					<p class="ha-posture-body">
						<?php
						$ha_url = (string) ( $ha_state['propertyUrl'] ?? '' );

						echo esc_html(
							'' !== $ha_url
								? $ha_url
								: __( 'Ready to bring your history across.', 'honest-analytics' )
						);
						?>
					</p>
				</div>
			</div>

			<p class="ha-gap-above">
				<a class="button button-primary" href="<?php echo esc_url( $screen->stepUrl( 'notes', 'ga4' ) ); ?>"><?php esc_html_e( 'Continue', 'honest-analytics' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( Connection::actionUrl( 'disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'honest-analytics' ); ?></a>
			</p>
		</div>
	</div>

<?php endif; ?>
