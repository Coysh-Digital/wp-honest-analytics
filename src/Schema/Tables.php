<?php
/**
 * Table names and the registries that keep maintenance honest.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place that knows what the tables are called, and two registries that stop
 * the garbage collector from quietly going wrong.
 *
 * Tables are blog-prefixed, so on multisite each site owns its own analytics
 * and `siteId` is `get_current_blog_id()`. The column is kept even though the
 * prefix already scopes the data: it costs four bytes, it keeps every query
 * portable to a shared-table layout later, and it makes a mis-scoped query
 * visible in a test rather than invisible in production.
 */
final class Tables {

	public const DIMENSIONS = 'honest_dimensions';
	public const SALTS      = 'honest_salts';
	public const DRAIN_LOG  = 'honest_drainlog';
	public const KV         = 'honest_kv';
	public const SESSIONS   = 'honest_sessions';
	public const SPOOL      = 'honest_spool';

	public const PAGES_ROLLUP        = 'honest_pages_rollup';
	public const PAGE_SOURCES_ROLLUP = 'honest_pagesources_rollup';
	public const SESSIONS_ROLLUP     = 'honest_sessions_rollup';
	public const SOURCES_ROLLUP      = 'honest_sources_rollup';
	public const DEVICES_ROLLUP      = 'honest_devices_rollup';
	public const UNIQUE_MEMBERS      = 'honest_uniquemembers';
	public const DAILY_UNIQUES       = 'honest_daily_uniques';
	public const CRAWLERS_ROLLUP     = 'honest_crawlers_rollup';

	public const CAMPAIGNS_ROLLUP = 'honest_campaigns_rollup';
	public const GEO_ROLLUP       = 'honest_geo_rollup';
	public const EVENTS_ROLLUP    = 'honest_events_rollup';
	public const SCROLL_ROLLUP    = 'honest_scroll_rollup';
	public const SEARCH_ROLLUP    = 'honest_search_rollup';
	public const OUTBOUND_ROLLUP  = 'honest_outbound_rollup';

	/**
	 * Search Console query data. Deliberately distinct from SEARCH_ROLLUP,
	 * which is this site's own on-site search box - a different concept
	 * entirely, not a second copy of the same one.
	 */
	public const SEARCHCONSOLE_ROLLUP = 'honest_searchconsole_rollup';

	public const GOALS              = 'honest_goals';
	public const GOALS_ROLLUP       = 'honest_goals_rollup';
	public const FUNNELS            = 'honest_funnels';
	public const FUNNEL_STEPS       = 'honest_funnelsteps';
	public const FUNNEL_STEP_ROLLUP = 'honest_funnelstep_rollup';

	public const CONSENT_LOG = 'honest_consentlog';

	public const IMPORTS         = 'honest_imports';
	public const IMPORT_COVERAGE = 'honest_import_coverage';
	public const IMPORT_LOG      = 'honest_import_log';
	public const JOURNEYS        = 'honest_journeys';

	public const SHARE_LINKS = 'honest_share_links';

	/**
	 * Reserved for a future segments rollup; not created, never queried.
	 *
	 * Present so `all()` reads in the same order as the Craft original when the
	 * table lands, and so a stale table from a pre-release install is dropped.
	 */
	private const SEGMENTS_PLACEHOLDER = 'honest_segments_rollup';

	/**
	 * A fully-prefixed table name.
	 *
	 * @param string $table One of the constants above.
	 */
	public static function name( string $table ): string {
		global $wpdb;

		$prefix = isset( $wpdb ) ? $wpdb->prefix : 'wp_';

		return $prefix . $table;
	}

	/**
	 * Every table the schema actually creates.
	 *
	 * `all()` is the list to drop, which includes tables a past version may
	 * have left behind. This is the list that exists, and it is the one to use
	 * for anything that reads, truncates or counts.
	 *
	 * @return string[] Unprefixed names.
	 */
	public static function created(): array {
		return array_values(
			array_filter(
				self::all(),
				static fn ( string $table ): bool => self::SEGMENTS_PLACEHOLDER !== $table
			)
		);
	}

	/**
	 * Every table this plugin owns, in an order safe to drop.
	 *
	 * Includes tables that are no longer created, so that uninstalling a site
	 * that has been through several versions leaves nothing behind.
	 *
	 * @return string[] Unprefixed names.
	 */
	public static function all(): array {
		return [
			self::SHARE_LINKS,
			self::FUNNEL_STEP_ROLLUP,
			self::FUNNEL_STEPS,
			self::FUNNELS,
			self::GOALS_ROLLUP,
			self::GOALS,
			self::IMPORT_LOG,
			self::IMPORT_COVERAGE,
			self::IMPORTS,
			self::JOURNEYS,
			self::CONSENT_LOG,
			self::CAMPAIGNS_ROLLUP,
			self::GEO_ROLLUP,
			self::EVENTS_ROLLUP,
			self::SCROLL_ROLLUP,
			self::SEARCH_ROLLUP,
			self::SEARCHCONSOLE_ROLLUP,
			self::OUTBOUND_ROLLUP,
			self::SEGMENTS_PLACEHOLDER,
			self::CRAWLERS_ROLLUP,
			self::DAILY_UNIQUES,
			self::UNIQUE_MEMBERS,
			self::DEVICES_ROLLUP,
			self::SOURCES_ROLLUP,
			self::SESSIONS_ROLLUP,
			self::PAGE_SOURCES_ROLLUP,
			self::PAGES_ROLLUP,
			self::SPOOL,
			self::SESSIONS,
			self::KV,
			self::DRAIN_LOG,
			self::SALTS,
			self::DIMENSIONS,
		];
	}

	/**
	 * Every table the retention window applies to.
	 *
	 * Anything date-keyed belongs either here or in `retainedElsewhere()`. A
	 * test walks the real schema and fails on a table in neither, because "no
	 * retention" and "different retention" are easy to confuse and only one of
	 * them is a promise the Privacy screen makes in writing.
	 *
	 * @return string[]
	 */
	public static function expiringRollups(): array {
		return [
			self::DAILY_UNIQUES,
			self::PAGES_ROLLUP,
			self::PAGE_SOURCES_ROLLUP,
			self::SESSIONS_ROLLUP,
			self::SOURCES_ROLLUP,
			self::DEVICES_ROLLUP,
			self::CRAWLERS_ROLLUP,
			self::CAMPAIGNS_ROLLUP,
			self::GEO_ROLLUP,
			self::EVENTS_ROLLUP,
			self::SCROLL_ROLLUP,
			self::SEARCH_ROLLUP,
			self::OUTBOUND_ROLLUP,
			self::GOALS_ROLLUP,
			self::FUNNEL_STEP_ROLLUP,
		];
	}

	/**
	 * Expiring rollups that carry a `source` column, and can therefore tell
	 * native rows from imported ones at retention time.
	 *
	 * A table listed here is only swept for its native rows - imported history
	 * is exempt from the retention window on purpose, so that history brought
	 * in from elsewhere outlives whatever window this site's own measurements
	 * are kept for.
	 *
	 * A table in `expiringRollups()` but missing from here is one no importer
	 * writes to, and the nightly tidy-up deletes everything past the cutoff in
	 * it unconditionally because there is nothing else a row in it could be.
	 * That used to be stated as "a table missing from here is one no importer
	 * has ever written to", which was not true: `honest_searchconsole_rollup`
	 * is written by GscRollupWriter, has no `source` column to be listed by,
	 * and was therefore swept whole - deleting imported Search Console history
	 * the moment it passed the site's own retention window, which is the exact
	 * opposite of what `docs/import-architecture.md` promises. It is now exempt
	 * by name in `retainedElsewhere()`.
	 *
	 * @return string[]
	 */
	public static function sourcedRollups(): array {
		return [
			self::DAILY_UNIQUES,
			self::PAGES_ROLLUP,
			self::PAGE_SOURCES_ROLLUP,
			self::SESSIONS_ROLLUP,
			self::SOURCES_ROLLUP,
			self::DEVICES_ROLLUP,
			self::GEO_ROLLUP,
		];
	}

	/**
	 * Date-keyed tables with a deliberately different rule, and what it is.
	 *
	 * @return array<string,string>
	 */
	public static function retainedElsewhere(): array {
		return [
			self::UNIQUE_MEMBERS       => 'Deleted two salt rotations after the day they cover: once the salt is destroyed the hashes match nothing.',
			self::DIMENSIONS           => 'Reference counted. A dimension outlives the rollups that referenced it only until the next garbage collection.',
			self::IMPORTS              => 'A record of what was imported and when. Kept, because deleting it would make a completed import look like one that never ran, and the next one would offer to do it again.',
			self::IMPORT_COVERAGE      => 'One row per imported day. This is what stops a second import doubling the first, so it outlives the rollups it describes on purpose.',
			self::IMPORT_LOG           => 'Trimmed by row count per import rather than by date: a log of the last few hundred batches is useful for support and an unbounded one is a liability.',
			self::SHARE_LINKS          => 'Not date-keyed at all: a row is kept until it is revoked or expires, then swept by GcService thirty days later so a just-expired link can still be seen as "expired" rather than simply vanishing.',
			self::SEARCHCONSOLE_ROLLUP => 'Imported history, and only ever imported: nothing native writes this table, so every row in it came from Search Console. Retention is a promise about what this plugin measures itself, not about history brought in from elsewhere, which is why it is exempt whole rather than by a source column that would read "gsc" on every row.',
		];
	}

	/**
	 * Every column that points at the dimensions table.
	 *
	 * The garbage collector deletes a dimension when nothing references it, so a
	 * column missing from this list means live dimensions get deleted and the
	 * reports that used them go quietly blank. A test reads the real schema and
	 * fails when the two disagree.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function dimensionReferences(): array {
		return [
			[ self::PAGES_ROLLUP, 'pathDimId' ],
			[ self::PAGE_SOURCES_ROLLUP, 'pathDimId' ],
			[ self::PAGE_SOURCES_ROLLUP, 'refHostDimId' ],
			[ self::SOURCES_ROLLUP, 'refHostDimId' ],
			[ self::DEVICES_ROLLUP, 'browserDimId' ],
			[ self::DEVICES_ROLLUP, 'osDimId' ],
			[ self::CAMPAIGNS_ROLLUP, 'sourceDimId' ],
			[ self::CAMPAIGNS_ROLLUP, 'mediumDimId' ],
			[ self::CAMPAIGNS_ROLLUP, 'campaignDimId' ],
			[ self::CAMPAIGNS_ROLLUP, 'termDimId' ],
			[ self::CAMPAIGNS_ROLLUP, 'contentDimId' ],
			[ self::GEO_ROLLUP, 'regionDimId' ],
			[ self::EVENTS_ROLLUP, 'eventNameDimId' ],
			[ self::EVENTS_ROLLUP, 'pathDimId' ],
			[ self::SCROLL_ROLLUP, 'pathDimId' ],
			[ self::SEARCH_ROLLUP, 'termDimId' ],
			[ self::SEARCHCONSOLE_ROLLUP, 'pathDimId' ],
			[ self::SEARCHCONSOLE_ROLLUP, 'queryDimId' ],
			[ self::OUTBOUND_ROLLUP, 'targetHostDimId' ],
			[ self::OUTBOUND_ROLLUP, 'targetDimId' ],
			[ self::OUTBOUND_ROLLUP, 'pathDimId' ],
			[ self::CRAWLERS_ROLLUP, 'crawlerDimId' ],
			[ self::JOURNEYS, 'pathDimId' ],
			[ self::JOURNEYS, 'eventDimId' ],
		];
	}
}
