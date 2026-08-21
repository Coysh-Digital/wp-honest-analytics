# Craft Analytics → Honest Analytics

A line-by-line account of what became what. Read it alongside
[`architecture.md`](architecture.md), which explains the decisions this table
merely records.

Anything marked **new** has no counterpart in the reference implementation and
exists because WordPress works differently. Anything marked **dropped** is
explained in ADR 49-52.

---

## Concepts

| Craft | WordPress | Notes |
|---|---|---|
| `siteId` (multi-site in one install) | `get_current_blog_id()`, and a separate table prefix per site | The column is kept and populated. See ADR 5. |
| `elementId` | `post_id` (`BIGINT UNSIGNED NULL`) | Nullable because a tracked path need not be a post. |
| Section | Post type | The Content screen groups by `post_type` via a join to `wp_posts`. |
| Entry type | - | **dropped**, ADR 52. |
| Category group / tag group | Taxonomy | **new**. Grouped through `term_relationships` + `term_taxonomy`, public taxonomies only, labelled "Category: Reviews". |
| Entry author | `post_author` | Same idea, cheaper join. |
| Element URI | Normalised request path | Craft can resolve an element from a URI. WordPress cannot cheaply, so `url_to_postid()` is used and memoised, and a missing match is not an error. |
| Craft user permissions | Custom capabilities | Table below. |
| Twig templates | PHP templates under `templates/` | Rendered by `Admin\Views\View`, which is `extract()` plus `include`. |
| Craft CP routes | `admin.php?page=honest-analytics…` | Query arguments rather than path segments, so the menu highlight survives. |
| Console controllers | WP-CLI commands | Table below. |
| Craft queue jobs (`TrackJob`) | The drain, driven by WP-Cron or the auto-drain | WordPress has no first-class queue. See ADR 25. |
| Craft's `Gc` event | `honest_analytics_gc` daily cron event | |
| Blitz cache integration | Cache-agnostic nonce dedupe (ADR 16) plus `Integrations\CacheDetector` | No cache plugin needs to know we exist. |
| Craft plugin settings model | One array option + constant/filter overrides | ADR 4. |
| `craft.craftAnalytics` Twig variable | `honest_analytics_views()`, `honest_analytics_popular_posts()`, `honest_analytics_gpc_detected()` | Plus the `[honest_analytics_views]` shortcode. |
| Craft events (`TrackEvent`, `DefineVisitorIdEvent`, …) | WordPress actions and filters, all prefixed `honest_analytics_` | Table below. |
| GraphQL (`src/gql`) | - | **dropped**, ADR 50. |
| Segments (`SegmentRegistry`, `SegmentDefinition`) | - | **dropped**, ADR 49. |
| Formie integration | Contact Form 7 and Gravity Forms | |
| Craft Commerce integration | WooCommerce | |

---

## Permissions

| Craft | WordPress |
|---|---|
| `craftAnalytics:view` | `honest_view_analytics` |
| `craftAnalytics:export` | `honest_export_analytics` |
| `craftAnalytics:manageSettings` | `honest_manage_analytics` |
| `craftAnalytics:viewAllSites` | - (network administrators, via `manage_network`) |

All three are granted to `administrator` at activation and are filterable.
`map_meta_cap` falls back to `manage_options` so a site that has never
activated cleanly still behaves sensibly.

---

## Classes

### Straight ports - same logic, same tests, different namespace

| Craft | Honest Analytics |
|---|---|
| `db/SchemaBuilder` | `Schema\Schema` (+ `Schema\Tables`, `Schema\Installer`, `Schema\Upgrader`) |
| `db/Upsert` | `Schema\Upsert` |
| `db/Table` | `Schema\Tables` |
| `ingest/Hit` | `Capture\Hit` |
| `ingest/CaptureService` | `Capture\CaptureService` |
| `ingest/NonceRegistry` | `Capture\NonceRegistry` |
| `ingest/ScriptInjector` | `Capture\ScriptInjector` |
| `write/SpoolWriter` | `Write\SpoolWriter` |
| `write/QueueWriter` | `Write\DbQueueWriter` |
| `write/DirectWriter` | `Write\DirectWriter` |
| `write/Drainer`, `DrainResult`, `SpoolStatus`, `AutoDrain`, `HitApplier` | same names under `Write\` |
| `write/TrackJob` | - (no queue; see ADR 25) |
| `rollup/*` | `Rollup\*`, name for name |
| `rollup/DimensionCapper` | `Dimensions\DimensionCapper` |
| `rollup/NullRollupSink` | - (tests use an in-memory sink instead) |
| `uniques/Hll`, `HllUniqueCounter`, `ExactUniqueCounter`, `UniqueScope`, `UniqueCounterInterface` | same names under `Uniques\` |
| `uniques/RedisUniqueCounter` | - (the object-cache store covers it; ADR 29) |
| `session/Session`, `SessionDelta` | `Sessions\Session`, `Sessions\SessionDelta` |
| `session/SessionStore` | `Sessions\SessionStoreInterface` + `CacheSessionStore` + `DbSessionStore` |
| `services/IdentityService`, `SaltService` | `Identity\*` |
| `services/BotFilter` | `Bots\BotFilter` |
| `services/DeviceParser` | `Devices\DeviceParser` |
| `services/ChannelClassifier` | `Channels\ChannelClassifier` |
| `services/DimensionsService` | `Dimensions\DimensionsService` |
| `services/GeoService` | `Geo\GeoService` (+ `Geo\GeoInstaller`) |
| `services/StatsService`, `ContentStatsService`, `ConversionStatsService` | `Stats\*` |
| `services/GoalsService`, `FunnelsService` | `Goals\*` |
| `services/ConsentService` | `Consent\ConsentService` |
| `services/PrivacyService`, `PrivacyDocumentService` | `Privacy\PrivacyService`, `Privacy\Posture` |
| `services/GcService` | `Gc\GcService` |
| `services/ReportMailer` | `Email\ReportMailer` |
| `services/SegmentRegistry` | - **dropped** |
| `models/DateRange` | `Stats\DateRange` |
| `models/Campaign` | `Channels\Campaign` |
| `models/Goal`, `Funnel` | `Goals\Goal`, `Goals\Funnel` |
| `models/Settings` | `Settings\Settings` (+ `Sanitizer`, `SettingsRepository`) |
| `models/SegmentDefinition` | - **dropped** |
| `charts/ChartData`, `Heatmap` | `Charts\*` (+ `Charts\Sparkline`) |
| `helpers/Csv` | `Export\Csv` |
| `helpers/RateLimit` | `Rest\RateLimit` |
| `helpers/Sparkline` | `Charts\Sparkline` |
| `helpers/ElementLinks` | `Admin\Posts\PostLinks` |
| `helpers/SiteAccess` | `Capabilities\Capabilities` |
| `enums/*` | `Channels\Channel`, `Channels\AttributionModel`, `Consent\ConsentMethod`, `Consent\ConsentState`, `Devices\DeviceType`, `Dimensions\DimensionType`, `Goals\GoalType` |

### Controllers → screens and routes

| Craft controller | Honest Analytics |
|---|---|
| `BeaconController` | `Rest\CollectController` (+ `Rest\PlainEndpoint` fallback) |
| `ConsentController` | `Rest\ConsentController` |
| `DashboardController` | `Admin\Screens\DashboardScreen`, `Admin\Screens\RealtimeScreen`, `Rest\RealtimeController` |
| `ReportsController` | `PagesScreen`, `SourcesScreen`, `DevicesScreen`, `CrawlersScreen` |
| `ProReportsController` | `CampaignsScreen`, `LocationsScreen`, `EventsScreen` |
| `ContentController` | `ContentScreen` |
| `ConversionsController` | `GoalsScreen`, `FunnelsScreen` |
| `GoalsController` | the goal and funnel editor inside `GoalsScreen` |
| `PrivacyController` | `PrivacyScreen` + `Privacy\PersonalData` |
| `ExportController` | `Export\ExportHandler` + `Export\Exporter` |
| `BaseCpController` | `Admin\Screens\Screen` + `Admin\RequestParams` |

### Console → WP-CLI

Every command keeps its name and its options.

| Craft | WP-CLI |
|---|---|
| `craft craft-analytics/drain` | `wp honest-analytics drain [--retry] [--watch] [--network] [--quiet]` |
| `craft craft-analytics/gc` | `wp honest-analytics gc [--dry-run] [--quiet]` |
| `craft craft-analytics/info` | `wp honest-analytics info` |
| `craft craft-analytics/salt/rotate` | `wp honest-analytics salt rotate [--yes]` / `salt status` |
| `craft craft-analytics/geo/install` | `wp honest-analytics geo install --file=<path>` / `--url=<url>`, plus `geo status` |
| `craft craft-analytics/privacy/export` | `wp honest-analytics privacy export [--visitor-id] [--user-id] [--format]` |
| `craft craft-analytics/privacy/erase` | `wp honest-analytics privacy erase [--visitor-id] [--user-id] [--include-consent-log] [--yes]`, plus `privacy posture` |
| `craft craft-analytics/report/send` | `wp honest-analytics report [<kind>] [--range] [--limit] [--format] [--email]` |
| `craft craft-analytics/seed` | `wp honest-analytics seed --days --per-day [--content] [--force]` |

### Widgets and surfaces

| Craft | WordPress |
|---|---|
| `widgets/OverviewWidget` | `Admin\Widgets\OverviewWidget` (`wp_add_dashboard_widget`, per-user meta for range and metrics) |
| `widgets/LiveWidget` | `Admin\Widgets\LiveWidget` |
| Entry edit sidebar panel | `Admin\Posts\StatsMetaBox` (classic and block editor) |
| Entry index column | `Admin\Posts\ViewsColumn` - **new**, batched, hideable through Screen Options |
| - | `wp_add_privacy_policy_content()` - **new** |
| - | `wp_privacy_personal_data_exporters` / `…_erasers` - **new** |

---

## Hooks

Craft events become WordPress hooks. All are prefixed `honest_analytics_`.

| Craft event | WordPress |
|---|---|
| `TrackEvent` | `do_action( 'honest_analytics_track_event', $name, $args )` |
| `DefineVisitorIdEvent` | `apply_filters( 'honest_analytics_visitor_hash', $hash, $context )` |
| `RegisterChannelRulesEvent` | `apply_filters( 'honest_analytics_channel_rules', $rules )` |
| `DefineConsentEvent` | `apply_filters( 'honest_analytics_consent_state', $state )` |
| `RegisterSegmentsEvent`, `DefineSegmentsEvent` | - **dropped** |
| - | `apply_filters( 'honest_analytics_exclude_user', $exclude, $user )` - **new** |
| - | `apply_filters( 'honest_analytics_is_pro', $isPro )` - **new** |
| - | `apply_filters( 'honest_analytics_settings', $settings )` - **new** |
| - | `apply_filters( 'honest_analytics_capabilities', $caps, $role )` - **new** |

---

## Storage

Table names lose the `craftanalytics_` prefix and gain `{$wpdb->prefix}honest_`.
Column names, types, indexes and unique keys are otherwise unchanged, except
where noted.

| Craft table | Honest Analytics table | Change |
|---|---|---|
| `dimensions` | `honest_dimensions` | - |
| `salts` | `honest_salts` | - |
| `drainlog` | `honest_drainlog` | - |
| `pages_rollup` | `honest_pages_rollup` | `elementId` → `post_id` |
| `pagesources_rollup` | `honest_pagesources_rollup` | - |
| `sessions_rollup` | `honest_sessions_rollup` | - |
| `sources_rollup` | `honest_sources_rollup` | - |
| `devices_rollup` | `honest_devices_rollup` | - |
| `uniquemembers` | `honest_uniquemembers` | - |
| `campaigns_rollup` | `honest_campaigns_rollup` | - |
| `geo_rollup` | `honest_geo_rollup` | - |
| `events_rollup` | `honest_events_rollup` | a per-event session counter added, so "sessions" and "per session" are real numbers rather than estimates |
| `scroll_rollup` | `honest_scroll_rollup` | - |
| `search_rollup` | `honest_search_rollup` | - |
| `outbound_rollup` | `honest_outbound_rollup` | - |
| `crawlers_rollup` | `honest_crawlers_rollup` | - |
| `goals`, `goals_rollup` | `honest_goals`, `honest_goals_rollup` | - |
| `funnels`, `funnelsteps`, `funnelstep_rollup` | `honest_funnels`, `honest_funnelsteps`, `honest_funnelstep_rollup` | - |
| `consentlog` | `honest_consentlog` | - |
| `journeys` | `honest_journeys` | - |
| - | `honest_kv` | **new** - nonces, rate limits, throttles when there is no object cache (ADR 29) |
| - | `honest_sessions` | **new** - the table-backed session store (ADR 30) |
| - | `honest_spool` | **new** - the table-backed write queue (ADR 23) |

Options:

| | |
|---|---|
| `honest_analytics_settings` | autoloaded |
| `honest_analytics_db_version` | autoloaded |
| `honest_analytics_licence` | network option first, then site option |
| `honest_analytics_last_drain`, `honest_analytics_last_gc` | not autoloaded |

---

## Behaviour that deliberately differs

Everything here is a considered divergence, not an omission.

**Two-phase trackability.** Craft decides in one place, because Yii's response
lifecycle exposes the status code before the response is sent. WordPress does
not, so the decision is split across `wp` and `shutdown` (ADR 13).

**Capture after flush.** Craft's `Response::EVENT_AFTER_SEND` has no direct
WordPress analogue. `fastcgi_finish_request()` on `shutdown` is the equivalent
(ADR 14).

**No queue.** Craft pushes a `TrackJob`; WordPress has cron, which is not a
queue. The spool plus drain plus auto-drain arrangement gets to the same place
with more moving parts and one fewer dependency (ADR 25).

**Search paths.** Craft has no built-in search route. WordPress's `?s=` would
otherwise create a row per search term, so the path is stored as `/?s=` and the
term goes to `search_rollup` (ADR 20).

**Plain permalinks.** Craft always has pretty URLs. WordPress may not, so
`p`, `page_id`, `cat`, `tag`, `paged`, `post_type` and `s` are protected from
query-string stripping and `stripQueryString` is forced off with a notice.

**Logged-in exclusion.** Craft excludes users with CP access. WordPress
excludes anybody who can `edit_posts`, which is the nearest honest equivalent,
and it is filterable.

**Bot markers.** The bot filter gains WordPress-specific preloader signatures -
WP Rocket's preloader, LiteSpeed's crawler, W3 Total Cache, NitroPack and SG
Optimizer - because on WordPress those generate a great deal of traffic that is
not a person.

**Object cache.** Craft assumes a cache component exists. WordPress may have
nothing but the request-scoped default, so every store has a database
implementation and `Health` warns when the cache in use is per-process (ADR 29).
