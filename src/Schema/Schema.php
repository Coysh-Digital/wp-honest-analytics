<?php
/**
 * Table definitions.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The database, defined once.
 *
 * Two properties are worth stating because everything else follows from them.
 *
 * There are no raw hit rows. A pageview is folded into a counter on the way in,
 * so the tables grow with dimensions multiplied by time, not with traffic: a
 * page viewed a million times occupies the same row as one viewed twice. The
 * single exception is `journeys`, which is off by default, needs consent, and
 * is the only table whose size tracks how busy the site is.
 *
 * And there is nowhere to put an IP address. Not a column, not a hash of one
 * kept around, not a log line. The address exists as a local variable inside
 * `IdentityService::visitorHash()` and is gone with the stack frame.
 */
final class Schema {

	/**
	 * Bumped whenever a statement below changes.
	 *
	 * `Upgrader` compares it with the stored version and re-runs dbDelta, which
	 * is additive: new tables, new columns and new indexes appear, nothing is
	 * dropped.
	 *
	 * Version 7 adds `honest_daily_uniques`, one pre-merged sketch per site per
	 * day, and backfills it from the per-path rows. See ADR 8.
	 *
	 * Version 6 added the indexes the maintenance sweep and the single-page
	 * queries were missing. Adding one to a large table takes a while and holds
	 * no lock that blocks reads on InnoDB, but it is still not something to do
	 * during somebody's page load - which is why `Upgrader::maybeUpgrade()` no
	 * longer runs from one. See ADR 58.
	 *
	 * The integer display widths stay. They look redundant - MySQL 8.0.19 and
	 * later drop them from column metadata - but core's dbDelta already ignores
	 * a difference that is only a display width, and does so *only* on MySQL:
	 * the check at `upgrade.php` excludes MariaDB explicitly, because MariaDB
	 * still reports them. Writing `bigint` instead of `bigint(20)` would
	 * therefore change nothing on MySQL and produce a redundant
	 * `ALTER TABLE ... CHANGE` for every integer column on every MariaDB
	 * install, on every version bump, for ever.
	 */
	public const VERSION = 7;

	/**
	 * Which tables were found this request.
	 *
	 * @var array<string,bool>
	 */
	private static array $tableExists = [];

	/**
	 * Every CREATE TABLE statement, in dbDelta's dialect.
	 *
	 * The dbDelta parser is fussy in specific ways - one column per line, two spaces after
	 * PRIMARY KEY, KEY rather than INDEX, lower-case types, no backticks - and
	 * silently does nothing useful when it is not obeyed. `SchemaTest` runs
	 * dbDelta twice and fails if the second pass reports work to do, which is
	 * the only reliable way to catch a statement it could not parse.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;
		$out     = [];

		// ---------------------------------------------------------------- spine

		$out[] = "CREATE TABLE {$p}honest_dimensions (
	id bigint(20) unsigned NOT NULL auto_increment,
	type smallint(6) NOT NULL,
	valueHash char(16) NOT NULL,
	value varchar(500) NOT NULL,
	firstSeen date NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY dimension_lookup (type,valueHash)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_salts (
	id smallint(6) NOT NULL,
	salt char(64) NOT NULL,
	rotatedAt datetime NOT NULL,
	nextRotation datetime NOT NULL,
	PRIMARY KEY  (id)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_drainlog (
	id bigint(20) unsigned NOT NULL auto_increment,
	batchId varchar(80) NOT NULL,
	driver varchar(16) NOT NULL,
	committedAt datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY batch (batchId),
	KEY committed (committedAt)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_kv (
	k varchar(191) NOT NULL,
	n bigint(20) NOT NULL default 0,
	v longtext NULL,
	expires bigint(20) NOT NULL default 0,
	PRIMARY KEY  (k),
	KEY expires (expires)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_sessions (
	siteId bigint(20) unsigned NOT NULL,
	sessionKey char(32) NOT NULL,
	visitorHash char(16) NOT NULL,
	startedAt bigint(20) NOT NULL default 0,
	lastSeenAt bigint(20) NOT NULL default 0,
	pageviews int(11) NOT NULL default 0,
	lastBatch varchar(80) NULL,
	closedByBatch varchar(80) NULL,
	data longtext NULL,
	PRIMARY KEY  (siteId,sessionKey),
	KEY idle (siteId,lastSeenAt),
	KEY sweep (lastSeenAt)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_spool (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	batchId varchar(80) NULL,
	line varbinary(4096) NOT NULL,
	createdAt bigint(20) NOT NULL default 0,
	PRIMARY KEY  (id),
	KEY claim (batchId,id)
) ENGINE=InnoDB $charset;";

		// -------------------------------------------------------- lite rollups

		$out[] = "CREATE TABLE {$p}honest_pages_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	hour smallint(6) NOT NULL,
	pathDimId bigint(20) unsigned NOT NULL,
	postId bigint(20) unsigned NULL,
	source varchar(24) NOT NULL default 'native',
	views int(11) NOT NULL default 0,
	uniques blob NULL,
	importedUniques int(11) NOT NULL default 0,
	totalDwellMs bigint(20) NOT NULL default 0,
	entrances int(11) NOT NULL default 0,
	exits int(11) NOT NULL default 0,
	bounces int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,hour,pathDimId,source),
	KEY by_post (siteId,postId,date),
	KEY by_date (date),
	KEY by_path (siteId,pathDimId,date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_pagesources_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	pathDimId bigint(20) unsigned NOT NULL,
	channel smallint(6) NOT NULL,
	refHostDimId bigint(20) unsigned NOT NULL default 0,
	source varchar(24) NOT NULL default 'native',
	views int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,pathDimId,channel,refHostDimId,source),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_sessions_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	hour smallint(6) NOT NULL,
	source varchar(24) NOT NULL default 'native',
	sessions int(11) NOT NULL default 0,
	bounces int(11) NOT NULL default 0,
	totalDurationMs bigint(20) NOT NULL default 0,
	totalPageviews int(11) NOT NULL default 0,
	uniques blob NULL,
	importedUniques int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,hour,source),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_sources_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	hour smallint(6) NOT NULL,
	channel smallint(6) NOT NULL,
	refHostDimId bigint(20) unsigned NOT NULL default 0,
	source varchar(24) NOT NULL default 'native',
	sessions int(11) NOT NULL default 0,
	bounces int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,hour,channel,refHostDimId,source),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_devices_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	browserDimId bigint(20) unsigned NOT NULL,
	browserMajor smallint(6) NOT NULL default 0,
	osDimId bigint(20) unsigned NOT NULL,
	deviceType smallint(6) NOT NULL default 0,
	source varchar(24) NOT NULL default 'native',
	sessions int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,browserDimId,browserMajor,osDimId,deviceType,source),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		// One row per site per day, holding the sketch of everybody who viewed
		// any page that day. The per-path rows in honest_pages_rollup can
		// answer this too - by merging every one of them - and that is what the
		// reports used to do: a thirty-day range at the default dimension cap
		// is around 191,000 rows, each carrying a sketch, deserialised and
		// merged in PHP, twice per render and four times with a comparison
		// period. Pre-merging at write time makes the same question one row per
		// day.
		$out[] = "CREATE TABLE {$p}honest_daily_uniques (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	source varchar(24) NOT NULL default 'native',
	uniques blob NULL,
	importedUniques int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,source),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_uniquemembers (
	id bigint(20) unsigned NOT NULL auto_increment,
	scopeKey varchar(120) NOT NULL,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	visitorHash char(16) NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY member (scopeKey,visitorHash),
	KEY expiry (siteId,date),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_crawlers_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	crawlerDimId bigint(20) unsigned NOT NULL,
	requests int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,crawlerDimId),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		// --------------------------------------------------------- pro rollups

		$out[] = "CREATE TABLE {$p}honest_campaigns_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	sourceDimId bigint(20) unsigned NOT NULL,
	mediumDimId bigint(20) unsigned NOT NULL default 0,
	campaignDimId bigint(20) unsigned NOT NULL default 0,
	termDimId bigint(20) unsigned NOT NULL default 0,
	contentDimId bigint(20) unsigned NOT NULL default 0,
	sessions decimal(12,4) NOT NULL default 0,
	bounces decimal(12,4) NOT NULL default 0,
	conversions decimal(12,4) NOT NULL default 0,
	value decimal(14,2) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,sourceDimId,mediumDimId,campaignDimId,termDimId,contentDimId),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_geo_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	countryCode char(2) NOT NULL,
	regionDimId bigint(20) unsigned NOT NULL default 0,
	source varchar(24) NOT NULL default 'native',
	sessions int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,countryCode,regionDimId,source),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_events_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	hour smallint(6) NOT NULL,
	eventNameDimId bigint(20) unsigned NOT NULL,
	pathDimId bigint(20) unsigned NOT NULL default 0,
	hits int(11) NOT NULL default 0,
	sessions int(11) NOT NULL default 0,
	sumValue decimal(14,2) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,hour,eventNameDimId,pathDimId),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_scroll_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	pathDimId bigint(20) unsigned NOT NULL,
	bucket smallint(6) NOT NULL,
	hits int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY depth (siteId,date,pathDimId,bucket),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_search_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	termDimId bigint(20) unsigned NOT NULL,
	hits int(11) NOT NULL default 0,
	zeroResults int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY term (siteId,date,termDimId),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_searchconsole_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	pathDimId bigint(20) unsigned NOT NULL,
	queryDimId bigint(20) unsigned NOT NULL,
	clicks int(11) NOT NULL default 0,
	impressions int(11) NOT NULL default 0,
	sumPosition decimal(14,2) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,pathDimId,queryDimId)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_outbound_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	targetHostDimId bigint(20) unsigned NOT NULL,
	targetDimId bigint(20) unsigned NOT NULL default 0,
	pathDimId bigint(20) unsigned NOT NULL default 0,
	hits int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,targetHostDimId,targetDimId,pathDimId),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		// ---------------------------------------------------- goals and funnels

		$out[] = "CREATE TABLE {$p}honest_goals (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(191) NOT NULL,
	handle varchar(64) NOT NULL,
	type varchar(16) NOT NULL,
	target varchar(500) NOT NULL,
	value decimal(14,2) NOT NULL default 0,
	enabled tinyint(1) NOT NULL default 1,
	sortOrder smallint(6) NOT NULL default 0,
	dateCreated datetime NOT NULL,
	dateUpdated datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY handle (handle)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_goals_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	goalId bigint(20) unsigned NOT NULL,
	conversions int(11) NOT NULL default 0,
	value decimal(14,2) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,goalId),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_funnels (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(191) NOT NULL,
	handle varchar(64) NOT NULL,
	enabled tinyint(1) NOT NULL default 1,
	sortOrder smallint(6) NOT NULL default 0,
	dateCreated datetime NOT NULL,
	dateUpdated datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY handle (handle)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_funnelsteps (
	id bigint(20) unsigned NOT NULL auto_increment,
	funnelId bigint(20) unsigned NOT NULL,
	goalId bigint(20) unsigned NOT NULL,
	position smallint(6) NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY step (funnelId,position),
	KEY goal (goalId)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_funnelstep_rollup (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	funnelId bigint(20) unsigned NOT NULL,
	position smallint(6) NOT NULL,
	sessions int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY bucket (siteId,date,funnelId,position),
	KEY by_date (date)
) ENGINE=InnoDB $charset;";

		// ------------------------------------------------------------- consent

		$out[] = "CREATE TABLE {$p}honest_imports (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	source varchar(24) NOT NULL,
	status varchar(16) NOT NULL,
	dateFrom date NULL,
	dateTo date NULL,
	configuration mediumtext NULL,
	cursorState mediumtext NULL,
	recordsProcessed bigint(20) unsigned NOT NULL default 0,
	recordsImported bigint(20) unsigned NOT NULL default 0,
	recordsSkipped bigint(20) unsigned NOT NULL default 0,
	daysTotal int(11) NOT NULL default 0,
	daysDone int(11) NOT NULL default 0,
	attempts int(11) NOT NULL default 0,
	lastError text NULL,
	retryAfter bigint(20) unsigned NOT NULL default 0,
	startedAt bigint(20) unsigned NOT NULL default 0,
	updatedAt bigint(20) unsigned NOT NULL default 0,
	completedAt bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	KEY by_site (siteId,source,status),
	KEY by_status (status,retryAfter)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_import_coverage (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	source varchar(24) NOT NULL,
	importId bigint(20) unsigned NOT NULL,
	date date NOT NULL,
	records int(11) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY covered (siteId,source,date),
	KEY by_import (importId)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_import_log (
	id bigint(20) unsigned NOT NULL auto_increment,
	importId bigint(20) unsigned NOT NULL,
	at bigint(20) unsigned NOT NULL,
	level varchar(10) NOT NULL,
	message text NOT NULL,
	context mediumtext NULL,
	PRIMARY KEY  (id),
	KEY by_import (importId,id)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_consentlog (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	visitorId varchar(64) NULL,
	visitorHash char(16) NULL,
	state varchar(16) NOT NULL,
	method varchar(16) NOT NULL,
	scope varchar(64) NOT NULL,
	policyVersion varchar(32) NOT NULL,
	recordedAt datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY visitor (visitorId),
	KEY recorded (siteId,recordedAt),
	KEY expiry_time (recordedAt)
) ENGINE=InnoDB $charset;";

		$out[] = "CREATE TABLE {$p}honest_journeys (
	id bigint(20) unsigned NOT NULL auto_increment,
	visitorId varchar(64) NOT NULL,
	siteId bigint(20) unsigned NOT NULL,
	sessionId char(32) NOT NULL,
	sequence int(11) NOT NULL default 0,
	pathDimId bigint(20) unsigned NOT NULL,
	eventDimId bigint(20) unsigned NULL,
	userId bigint(20) unsigned NULL,
	occurredAt datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY visitor (visitorId,occurredAt),
	KEY wp_user (userId),
	KEY site_time (siteId,occurredAt),
	KEY expiry_time (occurredAt),
	KEY sequence (visitorId,sessionId),
	KEY visitor_site (siteId,visitorId)
) ENGINE=InnoDB $charset;";

		// -------------------------------------------------------------- sharing

		$out[] = "CREATE TABLE {$p}honest_share_links (
	id bigint(20) unsigned NOT NULL auto_increment,
	siteId bigint(20) unsigned NOT NULL,
	tokenHash char(64) NOT NULL,
	label varchar(191) NOT NULL default '',
	scope varchar(24) NOT NULL default 'overview',
	windowPreset varchar(16) NOT NULL default '7d',
	sections varchar(191) NOT NULL default 'pages,sources',
	createdAt datetime NOT NULL,
	lastViewedAt datetime NULL,
	expiresAt datetime NULL,
	revokedAt datetime NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token (tokenHash),
	KEY by_site (siteId,revokedAt)
) ENGINE=InnoDB $charset;";

		return $out;
	}

	/**
	 * Create or update every table on the current site.
	 *
	 * @return string[] dbDelta's report of what it changed.
	 */
	public static function install(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$results = [];

		foreach ( self::statements() as $statement ) {
			$results = array_merge( $results, (array) dbDelta( $statement ) );
		}

		return $results;
	}

	/**
	 * Drop every table on the current site.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
			$wpdb->query( "DROP TABLE IF EXISTS `$name`" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Unprefixed table name.
	 */
	public static function tableExists( string $table ): bool {
		global $wpdb;

		$name = Tables::name( $table );

		// Memoised per request. A table cannot appear or vanish part way
		// through one, and this is asked on every dashboard and import screen
		// render - `SHOW TABLES LIKE` is not free on a database with a lot of
		// them, and it is asked to answer a question whose answer is almost
		// always yes.
		if ( isset( self::$tableExists[ $name ] ) ) {
			return self::$tableExists[ $name ];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		self::$tableExists[ $name ] = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return self::$tableExists[ $name ];
	}

	/**
	 * Forget which tables were found. For the installer, and for tests.
	 */
	public static function flushTableCache(): void {
		self::$tableExists = [];
	}
}
