<?php
/**
 * The Privacy screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_banner   = $posture->needsBanner();
$ha_warnings = $posture->warnings();
?>
<div class="ha-card">
	<div class="ha-card-body">
		<div class="ha-posture <?php echo $ha_banner ? 'is-consent' : 'is-clean'; ?>">
			<span class="ha-posture-icon" aria-hidden="true"><?php echo $ha_banner ? '!' : '&#10003;'; ?></span>

			<div>
				<div class="ha-posture-label"><?php esc_html_e( 'Current posture', 'honest-analytics' ); ?></div>
				<div class="ha-posture-title"><?php echo esc_html( $posture->title() ); ?></div>
				<p class="ha-posture-body"><?php echo esc_html( $posture->summary() ); ?></p>
			</div>
		</div>
	</div>
</div>

<?php if ( [] !== $ha_warnings ) : ?>
	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Worth knowing', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<?php foreach ( $ha_warnings as $ha_warning ) : ?>
				<div class="ha-warning">
					<span class="ha-badge"><?php esc_html_e( 'Check', 'honest-analytics' ); ?></span>
					<p class="ha-measure"><?php echo esc_html( $ha_warning ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

<div class="ha-card">
	<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'What this site collects', 'honest-analytics' ); ?></h2></div>

	<div class="ha-card-body">
		<div class="ha-scroll">
			<table class="ha-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Stored', 'honest-analytics' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Not stored', 'honest-analytics' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<?php foreach ( $posture->collects() as $ha_row ) : ?>
						<tr>
							<td><?php echo esc_html( $ha_row['yes'] ); ?></td>
							<td class="ha-muted"><?php echo esc_html( $ha_row['no'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<div class="ha-grid-2">
	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Identifiers held', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<dl class="ha-stack">
				<?php foreach ( $posture->identifiers() as $ha_identifier ) : ?>
					<div>
						<dt><?php echo esc_html( $ha_identifier['term'] ); ?></dt>
						<dd class="ha-muted"><?php echo esc_html( $ha_identifier['description'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Lawful basis', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<p class="ha-muted ha-tight-below"><?php echo esc_html( $posture->lawfulBasis() ); ?></p>
			<p class="ha-muted ha-tight"><?php esc_html_e( 'Enabling consented tracking, stored journeys or account linking changes this. Each of those switches carries its consequence on the Settings screen.', 'honest-analytics' ); ?></p>
		</div>
	</div>
</div>

<div class="ha-card">
	<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Configuration in detail', 'honest-analytics' ); ?></h2></div>

	<div class="ha-card-body">
		<table class="ha-table">
			<tbody>
				<?php foreach ( $posture->facts() as $ha_label => $ha_value ) : ?>
					<tr>
						<th scope="row" class="ha-rowhead">
							<?php echo esc_html( (string) $ha_label ); ?>
						</th>
						<td class="ha-muted"><?php echo esc_html( (string) $ha_value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<?php if ( $ha_banner ) : ?>
	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Consented data', 'honest-analytics' ); ?></h2></div>

		<div class="ha-card-body">
			<table class="ha-table">
				<tbody>
					<?php
					foreach (
						[
							__( 'Consent grants recorded', 'honest-analytics' )   => $counts['consentGrants'],
							__( 'Consent refusals recorded', 'honest-analytics' ) => $counts['consentDenials'],
							__( 'Visitors with stored journeys', 'honest-analytics' ) => $counts['consentedVisitors'],
							__( 'Journey rows', 'honest-analytics' )              => $counts['journeyRows'],
						] as $ha_label => $ha_value
					) :
						?>
						<tr>
							<th scope="row" class="ha-rowhead">
								<?php echo esc_html( (string) $ha_label ); ?>
							</th>
							<td class="ha-num"><?php echo esc_html( number_format_i18n( (int) $ha_value ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<div class="ha-card">
	<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Subject access and erasure', 'honest-analytics' ); ?></h2></div>

	<div class="ha-card-body">
		<?php if ( ! $ha_banner ) : ?>
			<p class="ha-muted">
				<?php esc_html_e( 'On this configuration there is nothing to export or erase: the rollups are counters and sketches with no individual inside them, and the hashes that fed them stopped being linkable the moment the salt rotated. These tools act on the consented journeys layer and the consent log, both of which are empty.', 'honest-analytics' ); ?>
			</p>
		<?php else : ?>
			<p class="ha-muted">
				<?php esc_html_e( 'A request reaches the consented journeys layer and the consent log. Aggregate statistics are out of scope: there is no individual inside a counter to find or remove.', 'honest-analytics' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $canManage ) : ?>
			<form method="post" class="ha-gap-above">
				<?php wp_nonce_field( 'honest-analytics-subject' ); ?>

				<div class="ha-form-row">
					<label class="ha-field">
						<?php esc_html_e( 'Visitor ID or account email', 'honest-analytics' ); ?>
						<input type="text" name="subject" placeholder="user@example.com" />
					</label>

					<button type="submit" name="honest_analytics_subject" value="export" class="button"><?php esc_html_e( 'Export data', 'honest-analytics' ); ?></button>
					<button type="submit" name="honest_analytics_subject" value="erase" class="button" data-ha-confirm="<?php esc_attr_e( 'Erase the stored journeys for this subject? This cannot be undone.', 'honest-analytics' ); ?>"><?php esc_html_e( 'Erase data', 'honest-analytics' ); ?></button>
				</div>

				<p class="ha-muted ha-fine">
					<?php esc_html_e( 'Erasure keeps the consent record by default: it is the evidence that the now-erased processing was lawful.', 'honest-analytics' ); ?>
				</p>
			</form>

			<?php if ( null !== $result ) : ?>
				<div class="ha-code ha-gap-above">
					<?php if ( 'erase' === $result['action'] ) : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: journey rows removed, 2: consent rows removed. */
								__( 'Removed %1$d journey row(s) and %2$d consent record(s).', 'honest-analytics' ),
								(int) $result['result']['journeys'],
								(int) $result['result']['consentLog']
							)
						);
						?>
					<?php else : ?>
						<pre class="ha-json"><?php echo esc_html( (string) wp_json_encode( $result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<?php /* What deleting the plugin will actually do, according to the setting as it stands - not a general warning that may not apply. */ ?>
<?php if ( $settings->keepDataOnUninstall ) : ?>
	<div class="ha-info">
		<p>
			<strong><?php esc_html_e( 'Deleting the plugin will leave your analytics tables in place.', 'honest-analytics' ); ?></strong>
			<?php esc_html_e( 'That is the default, because rollups cannot be rebuilt from anything else - no raw hit data is kept. A later install picks them straight back up. To have deletion remove them instead, turn off “Keep data on uninstall” on the Settings screen.', 'honest-analytics' ); ?>
		</p>
	</div>
<?php else : ?>
	<div class="ha-danger">
		<p>
			<strong><?php esc_html_e( 'Deleting the plugin will destroy every analytics table.', 'honest-analytics' ); ?></strong>
			<?php esc_html_e( '“Keep data on uninstall” is switched off, so deletion removes the lot. Rollups cannot be rebuilt from anything else, because no raw hit data is kept. Export what you need first. Deactivating the plugin is safe either way - it stops the scheduled work and leaves everything in place.', 'honest-analytics' ); ?>
		</p>
	</div>
<?php endif; ?>

<p class="ha-footnote">
	<?php esc_html_e( 'This screen describes what your configuration permits, not what has happened, because “a cookie could be set” is the compliance-relevant fact. It is not legal advice.', 'honest-analytics' ); ?>
</p>
