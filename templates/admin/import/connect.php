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

use HonestAnalytics\Import\Ga4\Client;
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
			<p class="ha-measure">
				<?php esc_html_e( 'Google will not let anything read your analytics until you tell Google that this site is allowed to ask. That is what the two values below are: a name and a password that identify this site to Google, and nothing else.', 'honest-analytics' ); ?>
			</p>

			<p class="ha-muted ha-measure">
				<?php esc_html_e( 'It takes about five minutes and costs nothing. You will not be asked for a card, and you do not need to understand any of it afterwards. Nothing about your visitors is sent to Google at any point; this only reads what Google already has.', 'honest-analytics' ); ?>
			</p>

			<h3 class="ha-subhead"><?php esc_html_e( 'Step 1. Copy this address', 'honest-analytics' ); ?></h3>

			<p class="ha-muted ha-measure ha-tight-below">
				<?php esc_html_e( 'Google asks for it in step 4, and matches it exactly, so copy it rather than typing it out.', 'honest-analytics' ); ?>
			</p>

			<div class="ha-form-row ha-copy-row">
				<label class="ha-visually-hidden" for="honest-ga4-redirect"><?php esc_html_e( 'Authorised redirect URI', 'honest-analytics' ); ?></label>

				<input
					type="text"
					id="honest-ga4-redirect"
					class="large-text code"
					readonly
					onfocus="this.select();"
					value="<?php echo esc_attr( (string) ( $ha_state['redirectUri'] ?? '' ) ); ?>"
				/>

				<button
					type="button"
					class="button"
					hidden
					data-ha-copy="honest-ga4-redirect"
					data-ha-copied="<?php esc_attr_e( 'Copied', 'honest-analytics' ); ?>"
				><?php esc_html_e( 'Copy', 'honest-analytics' ); ?></button>
			</div>

			<h3 class="ha-subhead"><?php esc_html_e( 'Step 2. Make a Google project', 'honest-analytics' ); ?></h3>

			<ol class="ha-plain-list ha-measure">
				<li>
					<?php
					printf(
						/* translators: %s: a link, reading "the Google Cloud console". */
						esc_html__( 'Open %s and sign in with the Google account that can already see the analytics you want to bring across.', 'honest-analytics' ),
						'<a href="https://console.cloud.google.com/projectcreate" rel="noopener noreferrer" target="_blank">'
							. esc_html__( 'the Google Cloud console', 'honest-analytics' )
							. '</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Create a project. The name is only for you, and no billing is involved.', 'honest-analytics' ); ?></li>
			</ol>

			<h3 class="ha-subhead"><?php esc_html_e( 'Step 3. Switch on the two things it needs to read', 'honest-analytics' ); ?></h3>

			<p class="ha-muted ha-measure ha-tight-below">
				<?php esc_html_e( 'Open each link below and choose Enable, in the same Google Cloud project. Miss the first and your property list comes back empty; miss the second and the import stops as soon as it starts.', 'honest-analytics' ); ?>
			</p>

			<ul class="ha-plain-list ha-measure">
				<li><a href="<?php echo esc_url( Client::enableApiUrl( Client::ADMIN_HOST ) ); ?>" rel="noopener noreferrer" target="_blank"><code>Google Analytics Admin API</code></a></li>
				<li><a href="<?php echo esc_url( Client::enableApiUrl( Client::DATA_HOST ) ); ?>" rel="noopener noreferrer" target="_blank"><code>Google Analytics Data API</code></a></li>
			</ul>

			<h3 class="ha-subhead"><?php esc_html_e( 'Step 4. Create the sign-in details', 'honest-analytics' ); ?></h3>

			<ol class="ha-plain-list ha-measure">
				<li><?php esc_html_e( 'Fill in the consent screen when the console asks: an app name, and your own email address twice. If your Google account belongs to a Workspace, choose Internal; otherwise choose External and add your own address as a test user.', 'honest-analytics' ); ?></li>
				<li><?php esc_html_e( 'Go to Credentials, then Create credentials, then OAuth client ID.', 'honest-analytics' ); ?></li>
				<li><?php esc_html_e( 'Choose Web application, not Desktop.', 'honest-analytics' ); ?></li>
				<li><?php esc_html_e( 'Under Authorised redirect URIs, paste the address you copied in step 1.', 'honest-analytics' ); ?></li>
				<li><?php esc_html_e( 'Create it, and keep the two values it shows you.', 'honest-analytics' ); ?></li>
			</ol>

			<h3 class="ha-subhead"><?php esc_html_e( 'Step 5. Paste them here', 'honest-analytics' ); ?></h3>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="honest_analytics_ga4_credentials" />
				<?php wp_nonce_field( 'honest-analytics-ga4-credentials' ); ?>

				<div class="ha-form-row">
					<label class="ha-field" for="honest-ga4-client-id">
						<?php esc_html_e( 'Client ID', 'honest-analytics' ); ?>
						<input
							type="text"
							id="honest-ga4-client-id"
							name="honest_ga4_client_id"
							class="regular-text"
							autocomplete="off"
							spellcheck="false"
							placeholder="000000000000-xxxxxxxx.apps.googleusercontent.com"
							data-ha-format="\.apps\.googleusercontent\.com$"
							data-ha-format-warning="<?php esc_attr_e( 'That does not look like a Google Client ID - they end in .apps.googleusercontent.com. Worth checking before you save.', 'honest-analytics' ); ?>"
						/>
						<span class="ha-field-hint" data-ha-format-hint hidden></span>
					</label>

					<label class="ha-field" for="honest-ga4-client-secret">
						<?php esc_html_e( 'Client secret', 'honest-analytics' ); ?>
						<input type="password" id="honest-ga4-client-secret" name="honest_ga4_client_secret" class="regular-text" autocomplete="off" placeholder="GOCSPX-..." />
					</label>

					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'honest-analytics' ); ?></button>
				</div>

				<p class="ha-muted ha-fine ha-measure">
					<?php esc_html_e( 'Both are stored on this site only. The secret is never sent to a browser, never appears in a report, and is deleted when you disconnect.', 'honest-analytics' ); ?>
				</p>
			</form>

			<p class="ha-muted ha-fine ha-measure ha-gap-above">
				<?php esc_html_e( 'Stuck, or something above not matching what you are seeing? The full version of these instructions, including what to do when Google says it has not verified this app, is in the Importing documentation that came with the plugin.', 'honest-analytics' ); ?>
			</p>
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
