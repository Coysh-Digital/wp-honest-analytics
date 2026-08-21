<?php
/**
 * The Settings screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

use HonestAnalytics\Admin\MaintenanceHandler;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Scheduling\Cron;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ha_name = static fn ( string $key ): string => SettingsRepository::OPTION . '[' . $key . ']';
$ha_id   = static fn ( string $key ): string => 'ha-' . $key;

/**
 * A checkbox control.
 */
$ha_switch = static function ( string $key, bool $value, string $label, ?string $override, ?string $toggle = null ) use ( $ha_name, $ha_id ): string {
	return sprintf(
		'<label><input type="checkbox" id="%s" name="%s" value="1" %s %s %s /> %s</label>',
		esc_attr( $ha_id( $key ) ),
		esc_attr( $ha_name( $key ) ),
		checked( $value, true, false ),
		null !== $override ? 'disabled' : '',
		null !== $toggle ? 'data-ha-toggle="' . esc_attr( $toggle ) . '"' : '',
		esc_html( $label )
	);
};

/**
 * A select control.
 */
$ha_select = static function ( string $key, string $value, array $options, ?string $override ) use ( $ha_name, $ha_id ): string {
	$html = '<select id="' . esc_attr( $ha_id( $key ) ) . '" name="' . esc_attr( $ha_name( $key ) ) . '"' . ( null !== $override ? ' disabled' : '' ) . '>';

	foreach ( $options as $ha_optionValue => $ha_optionLabel ) {
		$html .= sprintf(
			'<option value="%s" %s>%s</option>',
			esc_attr( (string) $ha_optionValue ),
			selected( $value, (string) $ha_optionValue, false ),
			esc_html( (string) $ha_optionLabel )
		);
	}

	return $html . '</select>';
};

/**
 * A number control.
 */
$ha_number = static function ( string $key, int $value, int $min, int $max, ?string $override, string $suffix = '', string $ariaLabel = '' ) use ( $ha_name, $ha_id ): string {
	// A row's <label for> points at its first control. Where a row holds two -
	// an interval and the hour it lands on - the second needs a name of its
	// own or a screen reader reads it as an unlabelled number.
	return sprintf(
		'<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text" %s%s /> %s',
		esc_attr( $ha_id( $key ) ),
		esc_attr( $ha_name( $key ) ),
		$value,
		$min,
		$max,
		'' !== $ariaLabel ? 'aria-label="' . esc_attr( $ariaLabel ) . '" ' : '',
		null !== $override ? 'disabled' : '',
		esc_html( $suffix )
	);
};

/**
 * A text control.
 */
$ha_text = static function ( string $key, string $value, ?string $override, string $placeholder = '', string $class = 'regular-text' ) use ( $ha_name, $ha_id ): string {
	return sprintf(
		'<input type="text" id="%s" name="%s" value="%s" placeholder="%s" class="%s" %s />',
		esc_attr( $ha_id( $key ) ),
		esc_attr( $ha_name( $key ) ),
		esc_attr( $value ),
		esc_attr( $placeholder ),
		esc_attr( $class ),
		null !== $override ? 'disabled' : ''
	);
};

/**
 * A textarea control for a list.
 */
$ha_list = static function ( string $key, array $value, ?string $override, string $placeholder = '' ) use ( $ha_name, $ha_id ): string {
	return sprintf(
		'<textarea id="%s" name="%s" rows="3" class="large-text code" placeholder="%s" %s>%s</textarea>',
		esc_attr( $ha_id( $key ) ),
		esc_attr( $ha_name( $key ) ),
		esc_attr( $placeholder ),
		null !== $override ? 'disabled' : '',
		esc_textarea( implode( "\n", $value ) )
	);
};

/**
 * Render one row.
 */
$ha_row = static function ( string $key, string $label, string $control, string $help, string $consequence = '' ) use ( $overrides, $ha_id ): void {
	View::render(
		'admin/partials/setting',
		[
			'label'       => $label,
			'control'     => $control,
			'help'        => $help,
			'consequence' => $consequence,
			'override'    => $overrides( $key ),
			'for'         => $ha_id( $key ),
		]
	);
};

/**
 * The keys submitted by this form, so unchecked boxes are read as "off"
 * without a missing checkbox resetting a group that was not on screen.
 */
$ha_submitted = [
	'trackingMode',
	'injectScript',
	'collectEndpoint',
	'excludeLoggedIn',
	'beaconRateLimit',
	'writeDriver',
	'autoDrain',
	'sessionWindow',
	'sessionStore',
	'spoolMaxBytes',
	'excludePaths',
	'excludeQueryParams',
	'stripQueryString',
	'honourGpc',
	'honourDnt',
	'ipSource',
	'trustedProxies',
	'hourlyWindowDays',
	'rollupRetentionMonths',
	'saltRotationInterval',
	'saltRotationHour',
	'dimensionCap',
	'uniqueCounterDriver',
	'hllPrecision',
	'blockCrawlers',
	'trackCrawlers',
	'enableCampaigns',
	'attributionModel',
	'enableGeo',
	'enableEvents',
	'trackOutbound',
	'trackDownloads',
	'trackScroll',
	'downloadExtensions',
	'trackSiteSearch',
	'siteSearchParam',
	'enableConsent',
	'consentCookieName',
	'consentCookieDuration',
	'cmpCookieName',
	'associateUserId',
	'enableJourneys',
	'journeyRetentionDays',
	'consentLogRetentionDays',
	'policyVersion',
	'enableScheduledReports',
	'reportRecipients',
	'reportPeriod',
	'keepDataOnUninstall',
];
?>

<?php if ( null !== $maintenance ) : ?>
	<div class="notice notice-<?php echo 'success' === $maintenance['type'] ? 'success' : 'error'; ?> is-dismissible">
		<p><?php echo esc_html( $maintenance['message'] ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! $health->isHealthy() ) : ?>
	<?php foreach ( $health->problems() as $ha_problem ) : ?>
		<div class="ha-info is-warning"><p><?php echo esc_html( $ha_problem ); ?></p></div>
	<?php endforeach; ?>
<?php else : ?>
	<div class="ha-info">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: when the last drain ran, 2: when the next runs. */
					__( 'WP-Cron is draining the spool every five minutes and is up to date. Last run %1$s; next %2$s.', 'honest-analytics' ),
					$health->lastDrainDescription(),
					$health->nextRunDescription( Cron::DRAIN_HOOK )
				)
			);
			?>
		</p>
	</div>
<?php endif; ?>

<div class="ha-card">
	<div class="ha-card-head">
		<h2 class="ha-card-title"><?php esc_html_e( 'Run maintenance now', 'honest-analytics' ); ?></h2>
		<span class="ha-card-note"><?php esc_html_e( 'Nothing here is required. It all runs on its own.', 'honest-analytics' ); ?></span>
	</div>

	<div class="ha-settings-body">
		<div class="ha-setting">
			<span class="ha-setting-label"><?php esc_html_e( 'Update the reports', 'honest-analytics' ); ?></span>

			<div>
				<div class="ha-setting-control">
					<form method="post" action="<?php echo esc_url( MaintenanceHandler::url() ); ?>">
						<?php wp_nonce_field( MaintenanceHandler::NONCE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( MaintenanceHandler::ACTION ); ?>">
						<input type="hidden" name="task" value="drain">
						<button type="submit" class="button"><?php esc_html_e( 'Drain now', 'honest-analytics' ); ?></button>
					</form>

					<?php if ( $quarantined > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( MaintenanceHandler::url() ); ?>">
							<?php wp_nonce_field( MaintenanceHandler::NONCE ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( MaintenanceHandler::ACTION ); ?>">
							<input type="hidden" name="task" value="retry">
							<button type="submit" class="button"><?php esc_html_e( 'Retry set-aside batches', 'honest-analytics' ); ?></button>
						</form>
					<?php endif; ?>
				</div>

				<p class="ha-setting-help">
					<?php esc_html_e( 'Folds whatever is waiting in the spool into the reports, here and now, without a terminal. It stops after twenty seconds and picks up where it left off, so a large backlog clears over a few presses.', 'honest-analytics' ); ?>
				</p>
			</div>
		</div>

		<div class="ha-setting">
			<span class="ha-setting-label"><?php esc_html_e( 'Tidy up', 'honest-analytics' ); ?></span>

			<div>
				<div class="ha-setting-control">
					<form method="post" action="<?php echo esc_url( MaintenanceHandler::url() ); ?>">
						<?php wp_nonce_field( MaintenanceHandler::NONCE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( MaintenanceHandler::ACTION ); ?>">
						<input type="hidden" name="task" value="tidy">
						<button type="submit" class="button"><?php esc_html_e( 'Run the tidy-up', 'honest-analytics' ); ?></button>
					</form>
				</div>

				<p class="ha-setting-help">
					<?php esc_html_e( 'Compacts finished days to daily totals and deletes anything past its retention. This is the one that acts on the retention settings below, and what it deletes does not come back.', 'honest-analytics' ); ?>
				</p>
			</div>
		</div>

		<div class="ha-setting">
			<span class="ha-setting-label"><?php esc_html_e( 'A real cron entry', 'honest-analytics' ); ?></span>

			<div>
				<div class="ha-setting-control">
					<pre class="ha-code">*/5 * * * * cd <?php echo esc_html( ABSPATH ); ?> &amp;&amp; wp honest-analytics drain --quiet
0 4 * * * cd <?php echo esc_html( ABSPATH ); ?> &amp;&amp; wp honest-analytics gc --quiet</pre>
				</div>

				<p class="ha-setting-help">
					<?php esc_html_e( 'Optional, and only if you have a terminal. On a busy site a real cron is steadier than WP-Cron, which only runs when somebody visits. Without either, ordinary traffic drives the drain, which is why the plugin works on hosts that offer no cron at all.', 'honest-analytics' ); ?>
				</p>
			</div>
		</div>
	</div>
</div>

<?php if ( [] !== $caches && Settings::TRACKING_SERVER === $settings->trackingMode ) : ?>
	<div class="ha-info is-warning">
		<p>
			<strong><?php echo esc_html( sprintf( /* translators: %s: cache plugin name. */ __( '%s is active and tracking mode is server-only.', 'honest-analytics' ), implode( ', ', array_keys( $caches ) ) ) ); ?></strong>
			<?php esc_html_e( 'Cached pages never reach PHP, so server-side tracking will silently under-count them - the numbers will look plausible and be wrong. Switch to hybrid: the beacon fills the gap automatically, with no cache configuration needed.', 'honest-analytics' ); ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( $delaysJs ) : ?>
	<div class="ha-info is-warning">
		<p><?php esc_html_e( 'WP Rocket’s “Delay JavaScript execution” is on. Unless the tracker is excluded from it, visitors who read a page and leave without clicking anything are never counted. The plugin asks to be excluded automatically, but check WP Rocket’s exclusion list if the figures look low.', 'honest-analytics' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
	<?php settings_fields( 'honest_analytics' ); ?>
	<input type="hidden" name="<?php echo esc_attr( SettingsRepository::OPTION ); ?>[_submitted]" value="<?php echo esc_attr( implode( ',', $ha_submitted ) ); ?>" />

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Tracking', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'trackingMode',
				__( 'Tracking mode', 'honest-analytics' ),
				$ha_select(
					'trackingMode',
					$settings->trackingMode,
					[
						Settings::TRACKING_HYBRID => __( 'Hybrid (recommended)', 'honest-analytics' ),
						Settings::TRACKING_SERVER => __( 'Server-side only', 'honest-analytics' ),
						Settings::TRACKING_CLIENT => __( 'Client beacon only', 'honest-analytics' ),
					],
					$overrides( 'trackingMode' )
				),
				__( 'Hybrid counts the view in PHP and confirms it from the browser, deduplicated by a one-time nonce. Server mode misses everything a page cache serves; client mode misses anyone with JavaScript blocked.', 'honest-analytics' )
			);

			$ha_row(
				'injectScript',
				__( 'Inject tracker', 'honest-analytics' ),
				$ha_switch( 'injectScript', $settings->injectScript, __( 'Add the tracker to public pages', 'honest-analytics' ), $overrides( 'injectScript' ) ),
				__( 'Adds a 1.3 KB first-party script before the closing body tag on public pages. Nothing is loaded from a CDN.', 'honest-analytics' )
			);

			$ha_row(
				'collectEndpoint',
				__( 'Collect endpoint', 'honest-analytics' ),
				$ha_select(
					'collectEndpoint',
					$settings->collectEndpoint,
					[
						Settings::ENDPOINT_REST  => __( 'REST API (recommended)', 'honest-analytics' ),
						Settings::ENDPOINT_PLAIN => __( 'Plain URL', 'honest-analytics' ),
					],
					$overrides( 'collectEndpoint' )
				),
				__( 'The first-party path the browser posts to. Switch to the plain URL if a security plugin or your host blocks anonymous access to /wp-json/.', 'honest-analytics' ),
				__( 'The endpoint is baked into cached HTML, so purge your page cache after changing this.', 'honest-analytics' )
			);

			$ha_row(
				'excludeLoggedIn',
				__( 'Exclude your own team', 'honest-analytics' ),
				$ha_switch( 'excludeLoggedIn', $settings->excludeLoggedIn, __( 'Do not count signed-in users who can edit posts', 'honest-analytics' ), $overrides( 'excludeLoggedIn' ) ),
				__( 'Covers administrators, editors, authors and contributors. Subscribers and shop customers are still counted, because they are visitors.', 'honest-analytics' )
			);

			$ha_row(
				'beaconRateLimit',
				__( 'Beacon rate limit', 'honest-analytics' ),
				$ha_number( 'beaconRateLimit', $settings->beaconRateLimit, 1, 100000, $overrides( 'beaconRateLimit' ), __( 'per visitor per minute', 'honest-analytics' ) ),
				__( 'A ceiling on what one visitor can post in a minute. It bounds abuse; it is not an access control.', 'honest-analytics' )
			);
			?>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Data writing and scheduled maintenance', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'writeDriver',
				__( 'Write driver', 'honest-analytics' ),
				$ha_select(
					'writeDriver',
					$settings->writeDriver,
					[
						Settings::WRITE_AUTO   => __( 'Automatic (recommended)', 'honest-analytics' ),
						Settings::WRITE_SPOOL  => __( 'File spool', 'honest-analytics' ),
						Settings::WRITE_DB     => __( 'Database queue', 'honest-analytics' ),
						Settings::WRITE_DIRECT => __( 'Direct', 'honest-analytics' ),
					],
					$overrides( 'writeDriver' )
				),
				sprintf(
					/* translators: %s: the driver currently in use. */
					__( 'The spool appends one line per view and the drain folds them into totals every few minutes. The database queue does the same with a row, for hosts with a read-only filesystem or several web servers. Currently using: %s.', 'honest-analytics' ),
					$writer
				),
				Settings::WRITE_DIRECT === $settings->writeDriver
					? __( 'Direct mode makes each pageview a database write, and two views in the same instant can race. It is for very small sites and for testing.', 'honest-analytics' )
					: ''
			);

			$ha_row(
				'autoDrain',
				__( 'Drain from traffic without cron', 'honest-analytics' ),
				$ha_switch( 'autoDrain', $settings->autoDrain, __( 'Run the drain occasionally on ordinary requests', 'honest-analytics' ), $overrides( 'autoDrain' ) ),
				__( 'A backup for hosts where cron is not an option. At most once a minute, after the visitor already has their page, so it never costs them anything. Leave it on even with cron: it only ever does anything in the gap between one run and the next.', 'honest-analytics' )
			);

			$ha_row(
				'sessionWindow',
				__( 'Session window', 'honest-analytics' ),
				$ha_number( 'sessionWindow', $settings->sessionWindow, 60, 14400, $overrides( 'sessionWindow' ), __( 'seconds', 'honest-analytics' ) ),
				__( 'How long somebody can be inactive before their next pageview counts as a new visit. 1,800 seconds is the industry norm, and matching it keeps your numbers comparable with everyone else’s.', 'honest-analytics' )
			);

			$ha_row(
				'sessionStore',
				__( 'Session storage', 'honest-analytics' ),
				$ha_select(
					'sessionStore',
					$settings->sessionStore,
					[
						Settings::STORE_AUTO  => __( 'Automatic', 'honest-analytics' ),
						Settings::STORE_CACHE => __( 'Object cache', 'honest-analytics' ),
						Settings::STORE_DB    => __( 'Database', 'honest-analytics' ),
					],
					$overrides( 'sessionStore' )
				),
				sprintf(
					/* translators: %s: the store currently in use. */
					__( 'Visits in progress are held here until they go quiet. Currently using: %s. Choose the database if your object cache is APCu-backed and you run more than one web server - sessions on one machine would otherwise be invisible to the next.', 'honest-analytics' ),
					$sessionStore
				)
			);
			?>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Paths and query strings', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'excludePaths',
				__( 'Excluded paths', 'honest-analytics' ),
				$ha_list( 'excludePaths', $settings->excludePaths, $overrides( 'excludePaths' ), "/cart/*\n/checkout/*" ),
				__( 'Glob patterns never tracked at all, one per line. The tracker is not even added to a page that matches.', 'honest-analytics' )
			);

			$ha_row(
				'stripQueryString',
				__( 'Strip query string', 'honest-analytics' ),
				$ha_switch( 'stripQueryString', $settings->stripQueryString, __( 'Record the clean path only', 'honest-analytics' ), $overrides( 'stripQueryString' ) ),
				__( 'Collapses every ?ad= and ?cid= variant of a page onto one clean path. Campaign parameters are read before stripping, so attribution is unaffected.', 'honest-analytics' ),
				'' === (string) get_option( 'permalink_structure', '' )
					? __( 'This site uses plain permalinks, where the query string is the page. The setting is forced off, because stripping it would collapse every page onto one row.', 'honest-analytics' )
					: ''
			);

			$ha_row(
				'excludeQueryParams',
				__( 'Extra parameters to strip', 'honest-analytics' ),
				$ha_list( 'excludeQueryParams', $settings->excludeQueryParams, $overrides( 'excludeQueryParams' ), "session_id\naffiliate" ),
				__( 'On top of the campaign and analytics parameters already removed. WordPress’s own page-identifying parameters cannot be added here.', 'honest-analytics' )
			);
			?>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Privacy signals', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'honourGpc',
				__( 'Honour Global Privacy Control', 'honest-analytics' ),
				$ha_switch( 'honourGpc', $settings->honourGpc, __( 'Respect Sec-GPC: 1', 'honest-analytics' ), $overrides( 'honourGpc' ) ),
				__( 'Visitors sending Sec-GPC: 1 have asked, in a legally recognised way, not to be tracked. With this on they are not counted at all, and nothing - not a consent platform, not your own event handler - can override it. A signal you can overrule is not a signal.', 'honest-analytics' ),
				$settings->honourGpc ? '' : __( 'GPC is treated as a valid opt-out under several privacy laws. Ignoring it is a legal risk, and the Privacy screen now says so.', 'honest-analytics' )
			);

			$ha_row(
				'honourDnt',
				__( 'Honour Do Not Track', 'honest-analytics' ),
				$ha_switch( 'honourDnt', $settings->honourDnt, __( 'Respect DNT: 1', 'honest-analytics' ), $overrides( 'honourDnt' ) ),
				__( 'Legacy Do Not Track support. Off by default because the standard is dead and most browsers no longer send it.', 'honest-analytics' )
			);

			$ha_row(
				'ipSource',
				__( 'Where the address comes from', 'honest-analytics' ),
				$ha_select(
					'ipSource',
					$settings->ipSource,
					[
						'auto'             => __( 'Automatic (recommended)', 'honest-analytics' ),
						'remote_addr'      => __( 'The connecting address only', 'honest-analytics' ),
						'cf_connecting_ip' => __( 'Cloudflare header', 'honest-analytics' ),
						'x_forwarded_for'  => __( 'X-Forwarded-For', 'honest-analytics' ),
						'x_real_ip'        => __( 'X-Real-IP', 'honest-analytics' ),
					],
					$overrides( 'ipSource' )
				),
				__( 'The address is only ever an input to the daily visitor hash and is discarded immediately - but behind a proxy every visitor arrives with the proxy’s address, and the site collapses to one visitor per browser type. Automatic trusts Cloudflare only when the connection genuinely came from Cloudflare.', 'honest-analytics' ),
				'x_forwarded_for' === $settings->ipSource
					? __( 'X-Forwarded-For is set by the client unless a proxy overwrites it. Add your proxy below, or anyone can mint unlimited visitor identities.', 'honest-analytics' )
					: ''
			);

			$ha_row(
				'trustedProxies',
				__( 'Trusted proxies', 'honest-analytics' ),
				$ha_list( 'trustedProxies', $settings->trustedProxies, $overrides( 'trustedProxies' ), "10.0.0.0/8\n192.168.1.5" ),
				__( 'IP addresses or CIDR ranges whose forwarding headers are believed. One per line. Leave empty unless you know which proxy sits in front of this site.', 'honest-analytics' )
			);
			?>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Retention', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'hourlyWindowDays',
				__( 'Hourly window', 'honest-analytics' ),
				$ha_number( 'hourlyWindowDays', $settings->hourlyWindowDays, 1, 90, $overrides( 'hourlyWindowDays' ), __( 'days', 'honest-analytics' ) ),
				__( 'How long rollups are kept hour by hour before lossless compaction to daily rows. This window is also the span the dashboard heatmap can cover.', 'honest-analytics' ),
				__( 'Raising this multiplies those rows by 24 for the extra period.', 'honest-analytics' )
			);

			$ha_row(
				'rollupRetentionMonths',
				__( 'Rollup retention', 'honest-analytics' ),
				$ha_number( 'rollupRetentionMonths', $settings->rollupRetentionMonths, 1, Settings::ROLLUP_MAX_RETENTION_MONTHS, $overrides( 'rollupRetentionMonths' ), __( 'months', 'honest-analytics' ) ),
				__( 'Hard capped at 26 months, which is the usual choice: it lets you compare this month against the same month last year, which 12 months does not. Older rows are removed by the nightly tidy-up.', 'honest-analytics' )
			);

			$ha_row(
				'saltRotationInterval',
				__( 'Salt rotation', 'honest-analytics' ),
				$ha_number( 'saltRotationInterval', $settings->saltRotationInterval, 3600, 31536000, $overrides( 'saltRotationInterval' ), __( 'seconds', 'honest-analytics' ) )
					. ' ' . $ha_number(
						'saltRotationHour',
						$settings->saltRotationHour,
						0,
						23,
						$overrides( 'saltRotationHour' ),
						__( ':00, site time', 'honest-analytics' ),
						__( 'Hour of the day the salt is rotated at, in the site’s timezone', 'honest-analytics' )
					),
				__( 'The visitor-hash salt is replaced and the old one destroyed. Rotation is aimed at your quietest hour, because a visitor active across the boundary becomes two sessions.', 'honest-analytics' ),
				__( 'Extending this is what would make visitors recognisable across days, and would require a consent mechanism.', 'honest-analytics' )
			);

			$ha_row(
				'dimensionCap',
				__( 'Daily cardinality cap', 'honest-analytics' ),
				$ha_number( 'dimensionCap', $settings->dimensionCap, 10, 100000, $overrides( 'dimensionCap' ), __( 'distinct values per day', 'honest-analytics' ) ),
				__( 'Past this many distinct paths, hosts or event names in a day, the rest fold into a single “Other” row. No views are lost; they are just not attributed individually. It is what stops one bad day becoming a permanent table.', 'honest-analytics' )
			);

			$ha_row(
				'uniqueCounterDriver',
				__( 'Unique visitor counting', 'honest-analytics' ),
				$ha_select(
					'uniqueCounterDriver',
					$settings->uniqueCounterDriver,
					[
						Settings::UNIQUES_HLL   => __( 'Estimated sketch (recommended)', 'honest-analytics' ),
						Settings::UNIQUES_EXACT => __( 'Exact', 'honest-analytics' ),
					],
					$overrides( 'uniqueCounterDriver' )
				),
				__( 'The sketch is a few kilobytes per row with no individual inside it, accurate to about 1.6%.', 'honest-analytics' ),
				Settings::UNIQUES_EXACT === $settings->uniqueCounterDriver
					? __( 'Exact counting keeps one row per visitor per day. The rows hold only a salted hash that stops meaning anything once the salt rotates, and they are deleted two rotations later - but it is more storage and a shorter distance to “individual” than the default.', 'honest-analytics' )
					: ''
			);
			?>
		</div>
	</div>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Crawlers', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'blockCrawlers',
				__( 'Keep crawlers out of the reports', 'honest-analytics' ),
				$ha_switch( 'blockCrawlers', $settings->blockCrawlers, __( 'Exclude recognised crawler traffic', 'honest-analytics' ), $overrides( 'blockCrawlers' ) ),
				__( 'Search engines, monitoring services and scrapers are not visitors. With this off, a site with 400 real visitors and 3,000 bot requests reports 3,400, and every number on every screen is wrong in a way that looks entirely plausible.', 'honest-analytics' ),
				$settings->blockCrawlers ? '' : __( 'Crawler traffic is currently being counted as if it were human.', 'honest-analytics' )
			);

			$ha_row(
				'trackCrawlers',
				__( 'Count crawlers separately', 'honest-analytics' ),
				$ha_switch( 'trackCrawlers', $settings->trackCrawlers, __( 'Record what was kept out', 'honest-analytics' ), $overrides( 'trackCrawlers' ) ),
				__( 'Records what was excluded, on its own screen, so that “my server logs say 4,000 requests and this says 400” has an answer. Costs one row per crawler per day - never a row per request.', 'honest-analytics' )
			);
			?>
		</div>
	</div>

	<?php if ( $isPro ) : ?>
		<div class="ha-card">
			<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'What gets tracked', 'honest-analytics' ); ?></h2></div>
			<div class="ha-settings-body">
				<?php
				$ha_row(
					'enableCampaigns',
					__( 'Campaigns and attribution', 'honest-analytics' ),
					$ha_switch( 'enableCampaigns', $settings->enableCampaigns, __( 'Read utm_ tags and ad click IDs', 'honest-analytics' ), $overrides( 'enableCampaigns' ) ),
					__( 'Shows which tagged links brought people, and what they did next. The tags are stripped from stored paths either way, so this does not change the Pages report.', 'honest-analytics' )
				);

				$ha_row(
					'attributionModel',
					__( 'Attribution model', 'honest-analytics' ),
					$ha_select(
						'attributionModel',
						$settings->attributionModel,
						[
							'last-click'  => __( 'Last non-direct click', 'honest-analytics' ),
							'first-click' => __( 'First click', 'honest-analytics' ),
							'linear'      => __( 'Linear', 'honest-analytics' ),
						],
						$overrides( 'attributionModel' )
					),
					__( 'How a session with more than one campaign touch divides the credit. With a single touch, every model agrees.', 'honest-analytics' )
				);

				$ha_row(
					'enableGeo',
					__( 'Geography', 'honest-analytics' ),
					$ha_switch( 'enableGeo', $settings->enableGeo, __( 'Record which country a visit came from', 'honest-analytics' ), $overrides( 'enableGeo' ) ),
					$geo['installed']
						? __( 'Resolved on this server from a local database. No lookup service is ever called, and nothing more precise than a country is derived.', 'honest-analytics' )
						: __( 'Needs a local database, which is not downloaded automatically. Install one from the Locations screen: upload the .mmdb file, or give it an address to fetch once. No terminal needed.', 'honest-analytics' )
				);

				$ha_row(
					'enableEvents',
					__( 'Events and interactions', 'honest-analytics' ),
					$ha_switch( 'enableEvents', $settings->enableEvents, __( 'Record custom events, outbound clicks, downloads and scroll depth', 'honest-analytics' ), $overrides( 'enableEvents' ), '[data-ha-events]' ),
					__( 'Loads a small extra script. Left off, none of these are recorded and the core tracker stays smaller.', 'honest-analytics' )
				);
				?>

				<div data-ha-events>
					<?php
					$ha_row(
						'trackOutbound',
						__( 'Outbound links', 'honest-analytics' ),
						$ha_switch( 'trackOutbound', $settings->trackOutbound, __( 'Count clicks that leave the site', 'honest-analytics' ), $overrides( 'trackOutbound' ) ),
						__( 'The destination URL is recorded, so keep an eye on it if your outbound links carry anything sensitive in their query strings.', 'honest-analytics' )
					);

					$ha_row(
						'trackDownloads',
						__( 'Downloads', 'honest-analytics' ),
						$ha_switch( 'trackDownloads', $settings->trackDownloads, __( 'Count clicks on downloadable files', 'honest-analytics' ), $overrides( 'trackDownloads' ) ),
						__( 'Same-site links whose extension is listed below.', 'honest-analytics' )
					);

					$ha_row(
						'downloadExtensions',
						__( 'Download extensions', 'honest-analytics' ),
						$ha_list( 'downloadExtensions', $settings->downloadExtensions, $overrides( 'downloadExtensions' ), "pdf\nzip" ),
						__( 'One per line, without the dot.', 'honest-analytics' )
					);

					$ha_row(
						'trackScroll',
						__( 'Scroll depth', 'honest-analytics' ),
						$ha_switch( 'trackScroll', $settings->trackScroll, __( 'Record how far down each page people read', 'honest-analytics' ), $overrides( 'trackScroll' ) ),
						__( 'Rides the existing pageview beacon, so it adds no extra request.', 'honest-analytics' )
					);
					?>
				</div>

				<?php
				$ha_row(
					'trackSiteSearch',
					__( 'Site search', 'honest-analytics' ),
					$ha_switch( 'trackSiteSearch', $settings->trackSiteSearch, __( 'Record what people search for on this site', 'honest-analytics' ), $overrides( 'trackSiteSearch' ), '[data-ha-search]' ),
					__( 'Read from the search URL, so it needs no JavaScript. The term goes to its own report and is removed from the stored path.', 'honest-analytics' )
				);
				?>

				<div data-ha-search>
					<?php
					$ha_row(
						'siteSearchParam',
						__( 'Search parameter', 'honest-analytics' ),
						$ha_text( 'siteSearchParam', $settings->siteSearchParam, $overrides( 'siteSearchParam' ), 's', 'small-text' ),
						__( 'The query-string parameter holding the search term. WordPress uses “s”; change it only if a search plugin uses something else.', 'honest-analytics' )
					);
					?>
				</div>
			</div>
		</div>

		<div class="ha-card">
			<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'Consent and durable tracking', 'honest-analytics' ); ?></h2></div>
			<div class="ha-settings-body">
				<?php
				$ha_row(
					'enableConsent',
					__( 'Consented tracking', 'honest-analytics' ),
					$ha_switch( 'enableConsent', $settings->enableConsent, __( 'Allow a first-party cookie for visitors who agree', 'honest-analytics' ), $overrides( 'enableConsent' ), '[data-ha-consent]' ),
					__( 'Lets visitors who affirmatively agree be counted across days and sessions.', 'honest-analytics' ),
					__( 'Turning this on sets a cookie, so the cookieless default no longer applies. You will need a consent mechanism and a privacy notice entry.', 'honest-analytics' )
				);
				?>

				<div data-ha-consent>
					<?php
					$ha_row(
						'consentCookieName',
						__( 'Cookie name', 'honest-analytics' ),
						$ha_text( 'consentCookieName', $settings->consentCookieName, $overrides( 'consentCookieName' ), '_ha_vid', 'small-text' ),
						__( 'The name of the first-party cookie set for consenting visitors.', 'honest-analytics' )
					);

					$ha_row(
						'consentCookieDuration',
						__( 'Cookie lifetime', 'honest-analytics' ),
						$ha_number( 'consentCookieDuration', $settings->consentCookieDuration, 3600, Settings::CONSENT_COOKIE_MAX_DURATION, $overrides( 'consentCookieDuration' ), __( 'seconds', 'honest-analytics' ) ),
						__( 'Hard capped at 24 months. Guidance in several jurisdictions expects consent to be refreshed at least annually.', 'honest-analytics' )
					);

					$ha_row(
						'cmpCookieName',
						__( 'Consent platform cookie', 'honest-analytics' ),
						$ha_text( 'cmpCookieName', $settings->cmpCookieName, $overrides( 'cmpCookieName' ), 'cookieyes-consent', 'regular-text' ),
						__( 'If you already run a consent platform, name its cookie and this will read it server-side. A value that is not on the accept list is treated as a refusal, not as “not asked”.', 'honest-analytics' )
					);

					$ha_row(
						'associateUserId',
						__( 'Link to WordPress accounts', 'honest-analytics' ),
						$ha_switch( 'associateUserId', $settings->associateUserId, __( 'Join consented analytics to user accounts', 'honest-analytics' ), $overrides( 'associateUserId' ) ),
						__( 'Records which signed-in account a consented visit belonged to.', 'honest-analytics' ),
						__( 'Being measured and being named are different agreements. Ask for the second one separately.', 'honest-analytics' )
					);

					$ha_row(
						'enableJourneys',
						__( 'Stored journeys', 'honest-analytics' ),
						$ha_switch( 'enableJourneys', $settings->enableJourneys, __( 'Keep per-visitor page histories', 'honest-analytics' ), $overrides( 'enableJourneys' ) ),
						__( 'The only table in this plugin that grows with traffic rather than with the number of distinct pages.', 'honest-analytics' ),
						__( 'This is personal data, with export and erasure obligations attached.', 'honest-analytics' )
					);

					$ha_row(
						'journeyRetentionDays',
						__( 'Journey retention', 'honest-analytics' ),
						$ha_number( 'journeyRetentionDays', $settings->journeyRetentionDays, 1, Settings::JOURNEY_MAX_RETENTION_DAYS, $overrides( 'journeyRetentionDays' ), __( 'days', 'honest-analytics' ) ),
						__( 'Hard capped at 26 months.', 'honest-analytics' )
					);

					$ha_row(
						'consentLogRetentionDays',
						__( 'Consent log retention', 'honest-analytics' ),
						$ha_number( 'consentLogRetentionDays', $settings->consentLogRetentionDays, 0, 36500, $overrides( 'consentLogRetentionDays' ), __( 'days, 0 to keep indefinitely', 'honest-analytics' ) ),
						__( 'Kept indefinitely by default. The record is the evidence that processing you have already carried out was lawful, which is why erasing a visitor deliberately leaves it behind.', 'honest-analytics' )
					);

					$ha_row(
						'policyVersion',
						__( 'Policy version', 'honest-analytics' ),
						$ha_text( 'policyVersion', $settings->policyVersion, $overrides( 'policyVersion' ), '1', 'small-text' ),
						__( 'Recorded against every consent decision. Bump it when your privacy notice changes materially, so old consents can be told apart from new ones.', 'honest-analytics' )
					);
					?>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="ha-card">
		<div class="ha-card-head"><h2 class="ha-card-title"><?php esc_html_e( 'When the plugin is deleted', 'honest-analytics' ); ?></h2></div>
		<div class="ha-settings-body">
			<?php
			$ha_row(
				'keepDataOnUninstall',
				__( 'Keep data on uninstall', 'honest-analytics' ),
				$ha_switch( 'keepDataOnUninstall', $settings->keepDataOnUninstall, __( 'Leave the analytics tables in place when the plugin is deleted', 'honest-analytics' ), $overrides( 'keepDataOnUninstall' ) ),
				__( 'On by default. The rollups cannot be rebuilt from anything else, because no raw hit data is kept, so throwing them away has to be something you choose rather than something that happens. Deactivating never deletes anything either way.', 'honest-analytics' ),
				$settings->keepDataOnUninstall ? '' : __( 'Uninstalling will drop every analytics table. There is no undo and no export afterwards.', 'honest-analytics' )
			);
			?>
		</div>
	</div>

	<div class="ha-save">
		<?php submit_button( __( 'Save changes', 'honest-analytics' ), 'primary', 'submit', false ); ?>
		<span class="ha-muted"><?php esc_html_e( 'Defaults are the privacy-preserving option in every group.', 'honest-analytics' ); ?></span>
	</div>
</form>

<?php if ( Edition::hasPro() ) : ?>
	<p class="ha-footnote">
		<?php esc_html_e( 'A licence key is not a setting, so it lives on its own screen:', 'honest-analytics' ); ?>
		<a href="<?php echo esc_url( $params->url( [], 'honest-analytics-licence' ) ); ?>"><?php esc_html_e( 'Licence', 'honest-analytics' ); ?></a>
	</p>
<?php endif; ?>
