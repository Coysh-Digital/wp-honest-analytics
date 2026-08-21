# Architecture

Honest Analytics is a port of [Craft Analytics][craft] to WordPress. The
behaviour, the metric definitions and the privacy guarantees come from that
plugin. The visual design comes from an approved prototype. The security and
accessibility conventions come from WordPress.

Where those three disagreed, this document records what was decided and why.

Each decision is numbered. The numbers do not change. If a decision is
reversed, the entry stays and gains a note.

[craft]: https://github.com/coysh-digital/craft-analytics

---

## Contents

- [The shape of the thing](#the-shape-of-the-thing) - ADR 1-5
- [Identity and privacy](#identity-and-privacy) - ADR 6-12
- [Capture](#capture) - ADR 13-21
- [Writing and aggregation](#writing-and-aggregation) - ADR 22-30
- [Reading](#reading) - ADR 31-35
- [The admin](#the-admin) - ADR 36-43
- [Editions and licensing](#editions-and-licensing) - ADR 44-48
- [What was dropped](#what-was-dropped) - ADR 49-52
- [Importing](#importing) - ADR 53-56

---

## The shape of the thing

### ADR 1 - Server-rendered PHP, not a React admin

**Decision.** Every screen is a PHP template rendered by WordPress, enhanced
afterwards by three small scripts (charts, polling, settings behaviour).

**Why.** A single-page app would need a build step, a bundle, a router, a data
layer and a second set of permission checks, and it would still be rendering
tables of numbers. WordPress already renders tables of numbers well. Server
rendering also means the reports work with JavaScript disabled, degrade to
plain HTML tables when Chart.js fails, and are readable by a screen reader
without any ARIA gymnastics.

The one genuinely interactive surface - Real-time - polls a REST route every
fifteen seconds and rewrites a table body. That does not justify a framework.

**Consequence.** No `npm run build`. `assets/` ships as written. The only Node
dependency in the repository is Playwright, and it exists to take screenshots.

### ADR 2 - Namespaced classes with a lazy container, not WordPress-style globals

**Decision.** PSR-4 under `HonestAnalytics\`, one class per file, constructor
injection where it helps. `Plugin` is a lazy service registry: services are
built on first use and memoised.

**Why.** The capture path runs on every uncached front-end request and must
allocate almost nothing. A lazy registry means a request that is not trackable
constructs two objects and stops. An eager container would build thirty.

**Consequence.** WPCS's file-naming and function-naming sniffs are excluded in
`phpcs.xml.dist`. The security, database and internationalisation sniffs are
not.

### ADR 3 - Dedicated tables, not options, post meta, transients or a custom post type

**Decision.** Twenty-six `{$wpdb->prefix}honest_*` tables, InnoDB, created with
`dbDelta()`, versioned by `honest_analytics_db_version`.

**Why.** Analytics data is relational, indexed by date and dimension, and read
with `GROUP BY`. `wp_options` autoloads. `wp_postmeta` has one index and no
type. Transients evict. A custom post type would put a million rows in
`wp_posts` and break every list screen on the site.

**Consequence.** The plugin owns its schema and its migrations. `Upgrader`
runs on `admin_init`, at the top of the drain, and at the top of every CLI
command, so an install that never opens the admin still upgrades itself.

### ADR 4 - One settings option, with constant and filter overrides

**Decision.** All settings live in a single `honest_analytics_settings` array
option, read through `SettingsRepository`, which layers a
`HONEST_ANALYTICS_SETTINGS` constant and a filter over the stored value.

**Why.** One option is one autoloaded read. Constant overrides let a host or a
version-controlled `wp-config.php` pin a setting that the admin cannot then
change by accident, which is how agencies actually run WordPress.

**Consequence.** An overridden field renders disabled with "Set in
`wp-config.php`" beneath it, so the admin screen never lies about what is in
effect.

### ADR 5 - Per-site tables on multisite

**Decision.** Each site in a network gets its own set of tables, its own
settings, its own cron events and its own spool. The licence is a network
option.

**Why.** Craft's `siteId` column exists because Craft multi-site is one
installation with one database. WordPress multisite is separate table
prefixes. Following the platform means `wp_2_honest_pages_rollup` rather than
a `site_id` filter on every query - smaller tables, simpler queries, and no
possibility of one site's report leaking another's rows.

**Consequence.** The `siteId` column is kept anyway, always populated with
`get_current_blog_id()`. It costs four bytes and it makes the port line up
with the reference implementation row for row, which mattered while checking
the aggregation logic. Cross-site isolation is asserted by an integration
test.

---

## Identity and privacy

### ADR 6 - An address is never written anywhere

**Decision.** The client address exists as a PHP string inside one function.
It is hashed with the current salt and discarded before the function returns.
It is never written to a table, a log, a cache key, the spool, the queue, or a
transient.

**Why.** This is the product. Everything else is a feature.

**Consequence.** There is no "IP anonymisation" setting, because there is no
IP to anonymise. `tests/Integration/NoIpPersistedTest.php` drives a request
with a known address and then greps every table, the key-value rows, the spool
file and the debug log for it.

### ADR 7 - Rotating daily salt, destroyed in place

**Decision.** `visitorHash = substr(sha256(salt | ip | userAgent | siteId), 0, 16)`.
The salt is a single row, overwritten in place at the configured hour in the
site's timezone, and also rotated lazily on read if the hour has passed.

**Why.** Sixteen hex characters is enough to distinguish visitors within a day
and far too little to reverse. Overwriting in place - rather than inserting a
new row - means yesterday's salt is genuinely gone, so yesterday's hashes
cannot be recomputed even by somebody holding the database and the address.

**Consequence.** "Unique visitors" means *daily* unique visitors. The same
person on two days is two uniques. This is stated on every screen that shows
the number, not buried in the documentation.

### ADR 8 - HyperLogLog sketches, merged and never summed

**Decision.** Uniqueness is counted with a HyperLogLog sketch stored on the
rollup row (precision 12, ±1.6%). Sketches from different rows are merged with
a bitwise maximum before the estimate is read.

**Why.** The alternative is a row per visitor per day per dimension, which is
both a privacy liability and a table that grows with traffic. A sketch is a
fixed-size blob that answers "how many distinct" without holding any of them.

**Consequence.** Uniques must never be added. Summing two days' unique counts
double-counts anybody who visited on both. `StatsService` merges; there is an
integration test that fails if anyone changes it to a `SUM()`.

An `exact` counter exists behind `uniqueCounterDriver` for sites that would
rather have exactness than the storage guarantee. It is not the default and
the Privacy screen says so.

### ADR 9 - Aggregate rollups, never raw hits

**Decision.** A pageview is applied to rollup rows and then ceases to exist.
There is no table of hits.

**Why.** Storage grows with dimensions × time, not pageviews × time. A site
with a hundred thousand views a day and a site with a hundred use the same
amount of disk.

**Consequence.** Reports cannot be recomputed after the fact, so a bug in
aggregation is not retroactively fixable. That is the trade, and it is the
right one. It also means deleting the tables is irreversible, which is why
`keepDataOnUninstall` defaults to **on**: the destructive option is one somebody
chooses, not the one they get for not reading a checkbox. The Privacy screen
states which way the setting currently stands rather than issuing a warning that
may not apply.

### ADR 10 - Referrer hosts, not referrer URLs

**Decision.** The referrer is reduced to a host, classified into a channel, and
the URL is discarded. The stored value is `example.com`, never
`https://example.com/private/document?token=…`.

**Why.** Full referrer URLs routinely contain session tokens, password-reset
links, internal paths and, on some intranets, names.

**Consequence.** "Which exact page linked to me" is not answerable. The
Sources screen shows hosts and channels, which is what the question usually
means anyway.

### ADR 11 - `Sec-GPC: 1` is honoured absolutely, and by default

**Decision.** A request carrying Global Privacy Control is not counted, not
spooled, and not sent a tracker. Not "counted anonymously" - not counted.

**Why.** GPC is a legally recognised opt-out signal in several jurisdictions
and an unambiguous statement of preference everywhere else. Honouring it
partially is worse than not claiming to honour it.

**Consequence.** Sites with a GPC-heavy audience will see lower numbers than a
third-party tool reports. The Privacy screen explains this, and
`honest_analytics_gpc_detected()` lets a theme say so to visitors.

Do Not Track is honoured too, but off by default, because browsers sent it
without asking anybody and its meaning is no longer clear.

### ADR 12 - Cookies, journeys and account association are separate, consented features

**Decision.** Nothing durable is written without an explicit, recorded consent.
The consent record and the journey data live in different tables with
different retention.

**Why.** A cookieless default that quietly acquires a cookie the first time an
optional feature is switched on is not a cookieless default.

**Consequence.** Turning on durable tracking requires a consent mechanism to be
configured first; the setting is disabled until it is. Cookie lifetime is
capped at 24 months and every retention setting at 26, in the sanitizer, not
in the UI.

---

## Capture

### ADR 13 - Trackability is decided in two phases

**Decision.** A snapshot is taken on the `wp` action at `PHP_INT_MAX`
(`RequestContext`). The verdict is reached on `shutdown` at `PHP_INT_MAX`
(`ResponseFacts` plus the exclusion rules).

**Why.** Some facts are only knowable early - the main query, the queried
object, the request headers before a plugin rewrites them. Others are only
knowable late - the final status code and content type. A 404 that a plugin
turns into a 200 must count; a 200 that a plugin turns into a redirect must
not.

**Consequence.** Two objects are allocated on requests that turn out not to be
trackable. Requests that can never be trackable - admin, AJAX, cron, REST,
XML-RPC, CLI - are rejected before the snapshot.

### ADR 14 - Counting happens after the response is flushed

**Decision.** `ShutdownRunner` calls `fastcgi_finish_request()` (or the
LiteSpeed equivalent, or falls back to `ignore_user_abort()`) and only then
writes.

**Why.** Analytics must not be in the critical path of a page load. The
visitor has their HTML before the plugin does anything at all.

**Consequence.** The capture cost does not appear in time-to-first-byte. On
servers where neither function exists, the write is still a single append to
one file, which is microseconds.

### ADR 15 - Hybrid by default: count on the server, confirm from the browser

**Decision.** Three modes - `server`, `client`, `hybrid` - with `hybrid` as
the default. The server counts what it sees; a small first-party tracker
confirms from the browser; a one-time nonce reconciles the two.

**Why.** Server-only misses cached pages entirely. Client-only misses everybody
with a content blocker, which for a privacy-conscious audience is most of
them. Hybrid counts both and deduplicates.

**Consequence.** The nonce is the interesting part. See ADR 16.

### ADR 16 - The dedupe nonce is keyed on (nonce, visitor), not on the nonce alone

**Decision.** A nonce is consumed once *per visitor hash*. The same visitor
presenting the same nonce twice is one view. A different visitor presenting
the same nonce is a second view.

**Why.** On a cached site, one nonce is baked into HTML that is served to
thousands of people. Keying on the nonce alone would count the page once, ever.
Keying on the pair counts each visitor once, which is the actual intent.

**Consequence.** Full-page caching works without any cache-plugin integration
and without punching a hole in the cache. There is an integration test for
both halves: same visitor twice is one view, two visitors on identical cached
HTML is two views.

### ADR 17 - First-party REST route, with a rewrite fallback

**Decision.** `POST /wp-json/honest-analytics/v1/collect`, with
`/?honest-analytics=collect` available as a fallback selectable in Settings.

**Why.** The REST API is the native mechanism and is already routed. But a
meaningful minority of sites disable it, move it, or put it behind an
authentication plugin, and an analytics beacon that 401s is useless. The
fallback runs on `parse_request` and needs nothing but pretty permalinks being
off or on.

**Consequence.** `RestUnlock` returns `null` from `rest_authentication_errors`
at maximum priority, but only for the two public routes, so a lockdown plugin
does not silently break collection while remaining in force everywhere else.

### ADR 18 - The collect endpoint always answers 204 with an empty body

**Decision.** Every response from `/collect` is `204 No Content`, zero bytes,
`Cache-Control: no-store`, whatever happened. Rejected, rate-limited,
deduplicated, accepted - 204.

**Why.** An analytics endpoint that reports its reasoning is an oracle. "Was
this nonce already used?" and "is this address rate-limited?" are questions a
public endpoint should not answer.

**Consequence.** Debugging the endpoint means reading the spool or running
`wp honest-analytics info`, not reading the response. `NoContent` enforces the
empty body through `rest_pre_serve_request`, because the REST server would
otherwise serialise a body for a 204.

### ADR 19 - Server referrers only, browser referrers never

**Decision.** The channel is classified from the `Referer` request header. The
`document.referrer` value the tracker could send is not read.

**Why.** Anything from the browser is attacker-controlled. Referrer spam is a
decades-old sport, and a referrer that arrives in a POST body is trivially
forged at volume.

**Consequence.** Client-only mode attributes less accurately than hybrid. The
Sources screen says so when the mode is `client`.

### ADR 20 - Campaign parameters are read before the path is normalised, then stripped

**Decision.** `Campaign::from()` reads the raw query string. `PathNormalizer`
then removes campaign parameters, advertising click identifiers and a list of
platform noise parameters before the path is stored.

**Why.** Order matters: normalise first and the campaign is gone; strip second
and `/pricing` is one row rather than four hundred.

**Consequence.** WordPress search is a special case. `?s=hats` is stored as
`/?s=` with the term recorded separately in `search_rollup`, so the search page
stays one row and the terms stay countable. Plain permalinks are another:
`p`, `page_id`, `cat`, `tag`, `paged`, `post_type` and `s` are protected from
stripping, and `stripQueryString` is forced off with a notice.

### ADR 21 - Address resolution is explicit, and forwarded headers are not trusted by default

**Decision.** `ipSource` is `auto`, `remote_addr`, `cf_connecting_ip`,
`x_forwarded_for` or `x_real_ip`. `auto` uses `CF-Connecting-IP` only when
`REMOTE_ADDR` is inside a published Cloudflare range, and `REMOTE_ADDR`
otherwise. `X-Forwarded-For` is never used unless chosen explicitly, with the
proxy listed.

**Why.** A forwarded header is a header. Trusting it by default lets anybody
choose their own identity - which, for a hashed daily identifier, means
inflating uniques at will.

**Consequence.** Sites behind a proxy that is not Cloudflare must configure
`ipSource` and `trustedProxies`. The Settings screen says what happens if they
do not: every visitor looks like the proxy, and uniques collapse to one.

---

## Writing and aggregation

### ADR 22 - Capture writes a line; aggregation happens later

**Decision.** The request appends one NDJSON line, at most 4096 bytes, to a
spool file with `LOCK_EX`. Rollups are computed by a separate drain.

**Why.** Aggregating a pageview touches a dozen tables with upserts. Doing
that on the request is a synchronous write amplification on the hot path, and
under concurrency it is a lock convoy.

**Consequence.** Numbers lag by up to the drain interval. Real-time is
therefore read from the session store rather than the rollups, which is why it
is a separate screen.

### ADR 23 - Spool to a file, fall back to a table

**Decision.** `writeDriver` is `auto`, `spool`, `db` or `direct`. `auto` uses
the file spool when the uploads directory is writable, and the `honest_spool`
table when it is not.

**Why.** An append to a locked file is the cheapest durable write available.
But read-only filesystems are common on containerised and managed hosting, and
an analytics plugin that silently stops counting is worse than a slow one.

**Consequence.** The spool directory is protected by `index.html`,
`.htaccess` (2.2 and 2.4 syntax) and `web.config`; the nginx rule is in
`docs/caching.md` because nginx cannot be configured from PHP. The filename
carries an HMAC so it cannot be guessed. `direct` is hidden unless `WP_DEBUG`
is on - it exists for tests, not for production.

### ADR 24 - Claim by rename, then batch, then transaction

**Decision.** The drain renames the spool file to claim it, reads it in chunks
of 20,000 lines, and applies each chunk inside a transaction that also writes a
batch marker to `drainlog`.

**Why.** Rename is atomic, so two concurrent drains cannot process the same
lines. The batch marker has a unique index, so a transaction that is retried
after a partial failure cannot apply the same chunk twice.

**Consequence.** A file that fails three times is quarantined rather than
retried forever, and `Health` raises it on the Settings screen. No data is
deleted; a quarantined file can be replayed by hand.

### ADR 25 - WP-Cron, with an auto-drain fallback and a real cron path

**Decision.** A five-minute `honest_analytics_drain` event, plus a
traffic-triggered auto-drain with a throttle, plus documented `wp-cron`
disabling with a system cron line or `wp honest-analytics drain`.

**Why.** WP-Cron only fires when somebody visits, which on a fully cached site
is almost never. The auto-drain covers that case: collection beacons reach PHP
even when pages do not, and a beacon can trip a drain.

**Consequence.** `Health` checks the last drain time, `DISABLE_WP_CRON`,
`ALTERNATE_WP_CRON`, missing events, quarantine and backlog, and shows the
exact cron line to paste. Back-pressure: when the spool exceeds
`spoolMaxBytes`, new lines are dropped rather than allowed to fill the disk,
and that is reported rather than hidden.

### ADR 26 - Hourly rollups compacted to daily

**Decision.** Rows are written per hour. After the retention window for hourly
detail, `Compactor` folds them into a row with `hour = -1`.

**Why.** Hourly detail is valuable for a week and noise for a year. Folding
reduces row count by roughly an order of magnitude.

**Consequence.** Compaction must be lossless for views and merge-correct for
sketches. It reads the existing daily row back in before merging, so running
it twice is safe. Verified on 21,156 rows folding to 3,467 with views and
uniques unchanged.

### ADR 27 - Dimension cardinality is capped first-N, not top-N

**Decision.** Each dimension accepts the first N distinct values it sees in a
period. Everything after that is bucketed as `__other__`.

**Why.** Top-N requires knowing the counts before deciding, which requires
storing everything, which is the problem being solved. First-N is decidable
online and bounded.

**Consequence.** A value that becomes popular after the cap is reached lands in
"Other" until the next period. The affected screens carry a footnote quoting
the configured cap, and "Other" is rendered as a non-clickable, muted row so
nobody mistakes it for a real page.

### ADR 28 - Upserts are additive, saturating and null-filling

**Decision.** `Upsert::counters()` builds
`INSERT … ON DUPLICATE KEY UPDATE col = col + VALUES(col)`, wraps sums in
`GREATEST(-cap, LEAST(cap, …))`, and uses `COALESCE(col, VALUES(col))` for
columns like `post_id` that should be filled once and then left alone.

**Why.** Additive upserts make the drain idempotent-in-shape and
order-independent. Saturation means a corrupt or hostile batch cannot overflow
a column. Null-filling means the first batch that knows the post ID sets it and
later batches do not churn it.

**Consequence.** MySQL-specific syntax. Documented as a requirement; the plugin
targets MySQL 5.7+/MariaDB 10.4+, which is below WordPress's own floor.

### ADR 29 - An object cache is used when present, and never required

**Decision.** `StoreFactory` returns an object-cache-backed store when
`wp_using_ext_object_cache()` is true, and a `honest_kv`-backed store
otherwise. Nonces, rate limits, the salt memo and the auto-drain throttle all
go through it. Mutexes always use MySQL `GET_LOCK`.

**Why.** Redis makes the hot path faster. Requiring Redis would exclude most of
WordPress.

**Consequence.** The DB fallback must never fail open - a rate limiter that
stops limiting when the cache is missing is not a rate limiter. It uses
`ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(n + 1)` to count atomically.
`Health` detects APCu-only caches, which are per-process and therefore
useless for cross-request dedupe, and recommends the DB store instead.

### ADR 30 - Sessions are a store, not a table decision

**Decision.** `SessionStoreInterface` with a cache implementation and a table
implementation. Real-time reads one indexed `SELECT` from the table store, or a
per-site index from the cache store.

**Why.** Same reason as ADR 29, with one extra constraint: Real-time must be a
single cheap query, because it runs every fifteen seconds per open admin tab.

**Consequence.** Session deltas are applied inside the drain transaction, so a
rolled-back batch does not leave sessions advanced. `closedByBatch IS NULL`
became `( closedByBatch IS NULL OR closedByBatch = '' )` because
`$wpdb->prepare()` renders a PHP null as an empty string - a bug that made
Real-time show zero visitors while the data was fine.

---

## Reading

### ADR 31 - Metric formulas are the reference implementation's, exactly

**Decision.** Site bounce rate is bounces ÷ sessions. Page bounce rate is
bounces ÷ *entrances*. Average time on page excludes the exit. Uniques merge.

**Why.** These are the definitions the reference implementation documents and
the ones its users have been reading for two years. A port that quietly
redefines bounce rate produces numbers that look like a regression.

**Consequence.** Where a formula is non-obvious it is repeated in a footnote
under the table, in the same words as the reference documentation.

### ADR 32 - Today is never served from cache

**Decision.** `ReportCache` caches for an hour, and refuses to cache any range
that includes today.

**Why.** A dashboard that is an hour stale on the current day looks broken, and
the current day is the one people refresh.

**Consequence.** Historical ranges are cheap; today costs a query. That is the
right way round.

### ADR 33 - Date ranges are validated, not trusted

**Decision.** `range` is a preset token or `YYYY-MM-DD:YYYY-MM-DD`, parsed into
a `DateRange` with a maximum span, in the site's timezone.

**Why.** A date range reaches SQL. An unbounded one reaches SQL slowly.

**Consequence.** `from`/`to` in a URL collapse to a canonical token so links
are stable and bookmarkable.

### ADR 34 - Comparison paths are authorised, not merely sanitized

**Decision.** `compare[]` values are checked against the rows actually on the
screen for the current range, capped at four, before any query runs.

**Why.** Sanitizing a path proves it is a path. It does not prove the person
asking is allowed to see its numbers. Private and draft content must not leak
through a comparison parameter.

**Consequence.** A comparison link cannot be hand-crafted to reveal a path the
requester could not otherwise reach.

### ADR 35 - Charts receive semantic tokens, never Chart.js configuration

**Decision.** PHP emits `{type, labels, datasets:[{label, data, token, fill}],
locale, options}`. `charts.js` resolves `token` against CSS custom properties
and builds the Chart.js configuration itself.

**Why.** If PHP emitted chart configuration, the theme would live in two
languages and every screen could invent its own. One module owning chart
creation means one place to fix a colour, one place to honour
`prefers-reduced-motion`, and one place to fall back.

**Consequence.** Categorical charts are limited to four series plus a neutral
"Other". Every chart has a semantic HTML table containing the same numbers,
hidden visually and revealed if Chart.js fails to render. The heatmap is an
HTML table always - never a canvas - because a canvas heatmap is unreadable
to a screen reader and unselectable to everybody.

---

## The admin

### ADR 36 - A top-level menu with native submenus, and no invented chrome

**Decision.** `add_menu_page()` with `dashicons-chart-bar`, then
`add_submenu_page()` for each screen. No sidebar of our own, no colour-scheme
selector, no custom header bar.

**Why.** The prototype drew a WordPress sidebar because a static mockup has no
WordPress around it. Shipping that would mean two sidebars.

**Consequence.** The plugin inherits the user's real admin colour scheme.
`--ha-primary` is `var(--wp-admin-theme-color, #2271b1)`, so a user on the
Ectoplasm scheme gets Ectoplasm charts.

A subtlety worth recording: registering the parent slug as a submenu too gives
that hook *two* registered callbacks, which renders the dashboard twice and
produces duplicate canvas IDs, which breaks the chart. `Screen::register()`
distinguishes the parent case and `Menu` binds the dashboard submenu
explicitly.

### ADR 37 - Custom capabilities, checked at every boundary

**Decision.** `honest_view_analytics`, `honest_export_analytics`,
`honest_manage_analytics`, granted to administrators at activation, with
`map_meta_cap` falling back to `manage_options`.

**Why.** "Can this person edit the site" and "can this person see the traffic
report" are different questions. An editor may want reports without settings;
a client may want reports without anything else.

**Consequence.** Every admin screen, every export, every REST route and every
CLI command that reads data checks a capability. The public collect and consent
routes deliberately do not, and are defended by rate limiting and ADR 18
instead.

### ADR 38 - Settings post to `options.php` through a real sanitizer

**Decision.** `register_setting()` with a `Sanitizer` callback that validates
each field for its meaning - a hostname as a hostname, a retention period
against its cap, a handle against its pattern - and merges posted keys over the
stored array.

**Why.** `sanitize_text_field()` on a number is not validation. And a partial
POST must not blank the settings it did not include.

**Consequence.** Settings behaves like every other WordPress settings page:
same nonce handling, same "Settings saved." notice, same capability check.

### ADR 39 - Escape at output, always, with no exceptions for "our own" data

**Decision.** Templates escape at the final boundary. Data that came from the
database is escaped exactly like data that came from a request.

**Why.** A path in `pages_rollup` arrived there from a URL. It is not our data;
it is a stranger's data that we stored.

**Consequence.** Report values are escaped even though they are numbers.

### ADR 40 - CSV exports are defused

**Decision.** `fputcsv` with an empty escape character, plus an apostrophe
prefixed to any field beginning with `=`, `+`, `-` or `@` after leading
whitespace. Numbers are left alone.

**Why.** A page path is attacker-chosen. `=cmd|'/c calc'!A1` as a URL is a
spreadsheet formula the moment somebody opens the export.

**Consequence.** A path that genuinely starts with a hyphen gains a leading
apostrophe in the CSV. Correctness beats tidiness.

### ADR 41 - Chart.js is vendored, with provenance

**Decision.** `assets/admin/js/vendor/chart.umd.js` is committed, alongside its
licence and a `PROVENANCE.md` recording version and SHA-256. `bin/check-budgets.php`
verifies the hash.

**Why.** A CDN is a third party watching the admin. The requirement is no CDN
for front-end resources; extending it to the admin costs nothing and removes
an external dependency from a plugin whose entire premise is not calling
anybody.

**Consequence.** Upgrading Chart.js is a deliberate commit with an updated
hash.

### ADR 42 - The tracker is small, dependency-free and enqueued normally

**Decision.** `assets/js/tracker.js`, under 2KB gzipped, enqueued with
`wp_enqueue_script()` in the footer with `strategy => 'defer'`,
`navigator.sendBeacon()` with a `fetch(keepalive)` fallback.

**Why.** Normal enqueueing means it participates in the platform: dependency
ordering, deregistration, optimisation plugins, `SCRIPT_DEBUG`. Printing a raw
`<script>` tag would bypass all of it.

**Consequence.** `OptimizerExclusions` registers the handle with WP Rocket,
Autoptimize, LiteSpeed, SG Optimizer and Perfmatters so their delay-JS features
do not defer the beacon until after the visitor has left. The size limit is
enforced by a test, not by intent.

### ADR 43 - Cached pages and private content

**Decision.** Analytics for a post is only shown to somebody who could read the
post. The "Views" column and the editor panel check `current_user_can()`
against the specific post.

**Why.** Otherwise a contributor learns the traffic of a private draft they
cannot open.

**Consequence.** The column is memoised in a single batched query per screen,
because a per-row query on a list table is how list tables become slow.

---

## Editions and licensing

### ADR 44 - One question, asked in one place

**Decision.** `Edition::isPro()` reads the build constant, then the development
constant, then a filter, then the licence state. Nothing else in the codebase
asks the question.

**Why.** Edition checks scattered through templates rot. One gate can be
tested, and can be flipped in development with a single constant.

**Consequence.** The check sits in front of the query, not in front of the
markup, so a downgraded site does not quietly keep computing figures it will
not show. Lite keeps the Pro rows in the menu, marked, leading to a page that
describes the report rather than a 403 (ADR 57).

### ADR 45 - Three editions, two behaviours

**Decision.** Lite, Pro and Agency. **Pro and Agency are the same build with
the same features**; the licence decides how many sites may use it and nothing
else. `Edition::isAgency()` exists for the Licence screen and for nothing else.

**Why.** An Agency tier that unlocked extra features would need a second gate,
a second set of tests and a second thing to get wrong. A seat count is a
commercial fact, not a technical one, and belongs in the licence rather than in
the code.

**Consequence.** No feature anywhere asks whether this is Agency. The
activation cap comes from the provider's response and is never hard-coded, so
raising it is a decision made outside the plugin.

### ADR 46 - Lite is a product, not a trial

**Decision.** Pageviews, daily uniques, sessions, bounce rate, Real-time, Pages
and page detail, Content, Sources, Devices, Privacy, Settings, **CSV and JSON
export**, and every WordPress-native surface - both dashboard widgets, the post
list column, the editor panel - are in Lite. Campaigns, Locations, Events,
Goals, Funnels, **Crawlers**, the third-party integrations, consented durable
tracking and scheduled summaries are Pro.

**Why.** A privacy-first analytics plugin that cannot tell you your top pages
without payment is not a privacy-first analytics plugin. Two boundary calls
went against the obvious commercial answer on purpose: **export stays in Lite**,
because a plugin whose premise is that the data is yours cannot withhold the
download button for data the site collected itself; and **crawlers moves to
Pro**, because Lite still excludes crawler traffic from every figure and only
the breakdown is withheld.

**Consequence.** No nag screens, no countdowns, no expiry warnings, and no
artificial limits on rows, retention or date ranges. The Pro placeholders on
the Dashboard are one muted card each, stating what the section would contain.
They are not banners, they do not animate, and they do not follow the scroll.

### ADR 47 - A licence that cannot expire, and cannot fail closed

**Decision.** One `LicenceProviderInterface` - `activate`, `deactivate`,
`check`, `updateCheck` - with an offline implementation as the default. The
licence is a one-off purchase with **no expiry**. Support lapses after twelve
months and **withdraws nothing**. A provider outage keeps the last known good
state.

**Why.** Two failure modes are worse than never checking at all: a site whose
owner paid once losing its reports because somebody else's server is down, and
a plugin that quietly starts withholding features when a support window closes.
Both are how analytics tools lose people's trust, and this one is called Honest
Analytics.

**Consequence.** `LicenceState` deliberately has no `isExpired()`; the docblock
says why, so the next person is not tempted. A failed `check()` returns the
cached state - there is a test that makes the provider throw and asserts the
edition holds. A failed *first* activation stays Lite, because nothing has ever
confirmed that key, but it says "could not be checked just now" rather than
calling the key bad. No provider SDK, endpoint or payload shape is named
anywhere in the codebase.

### ADR 48 - Lite is stripped, not disabled

**Decision.** Two builds from one source tree: Lite for wordpress.org with the
Pro source and templates removed, Pro for direct download with everything.
`HONEST_ANALYTICS_HAS_PRO` identifies the build and defaults to true when
absent, so the working tree behaves as Pro.

**Why.** wordpress.org will not accept a package full of paid code sitting
behind a key, and shipping one would be dishonest in a different way: a user
reading the source of a free plugin should find the free plugin.

**Consequence.** The strip list is a manifest a person can read, and the build
fails if the Lite staging tree still references anything it removed. Table
names come from `Schema\Tables` and never from the slug or the edition, so Pro
reads exactly what Lite wrote - upgrading migrates nothing, and downgrading
deletes nothing.

---

## What was dropped

### ADR 49 - No segments in v1

The reference implementation's segment registry is a query-building layer with
its own storage and UI. It is not in the prototype's navigation, and porting it
would have delayed the screens that are. The dimension tables are shaped to
accept it later.

### ADR 50 - No GraphQL

Craft's GraphQL is a first-class part of that platform. WordPress's is a
plugin. The REST routes cover the same ground for the same consumers.

### ADR 51 - No map on the Locations screen

The prototype shows a country table, not a choropleth. A map needs either
vector data bundled in the plugin or a tile server, and a tile server is a
third party watching the admin. The table is also the accessible artefact.

### ADR 52 - Entry types have no WordPress equivalent, and the prototype agrees

Craft sections and entry types map to post types and - nothing. The prototype's
Content tabs are Post types, Taxonomies and Authors, which is the WordPress
shape of the same question. Taxonomies are new; entry types are gone.

---

## Importing

### ADR 53 - Provenance belongs in the unique key, not beside it

**Decision.** `source varchar(24)` on the six rollup tables an import writes,
and **in their unique keys**. Native rows say `native`; imported rows say where
they came from.

**Why.** A column that is merely present tells you where a row came from. A
column in the key stops the rows merging. Without it, importing a month the
plugin had also measured natively would have added the two together in place,
invisibly and irreversibly.

**Consequence.** Native and imported figures for the same day are separate rows
that can be counted, explained and removed separately. Whether they should be
added when a range covers both becomes a question the overlap check asks the
user, rather than one the schema answers by accident. The migration widens the
keys with an explicit `ALTER` before dbDelta runs, because dbDelta adds a
missing index and never changes an existing one - left to it, the old key would
have survived and the merge would have happened anyway.

### ADR 54 - Imported visitors are added to the estimate, not merged into it

**Decision.** `importedUniques int(11)` beside the sketch. The read path adds it
to the HyperLogLog estimate rather than folding it in.

**Why.** Every source being imported reports a visitor *number*. Turning a
number back into a sketch means inventing the identities it was built from, and
a sketch of invented identities is a lie that merges convincingly. It would also
have cost a hash per visitor per row - billions of operations across four years
of history, to reproduce a figure the source had already given us.

**Consequence.** Adding is correct rather than merely cheap. Uniques here are
daily; the salt is destroyed every night, so two native days already sum rather
than deduplicate. An imported day and a native day sum for exactly the same
reason.

### ADR 55 - A day is imported whole, replacing whatever was there

**Decision.** `ImportSink::write()` deletes every row that source wrote for that
date and inserts the day, in one transaction.

**Why.** Idempotency by construction rather than by arithmetic. Running an
import twice replaces the first run instead of doubling it, and that holds on
every retry path without anyone having to reason about deltas.

**Consequence.** An import that fails halfway can simply be run again. The unit
of progress is a day, which is also the unit every source agrees on, so the
cursor is a date and resuming is obvious. Coverage is recorded per day, so
"already imported" is an indexed lookup rather than a scan of the rollups.

### ADR 56 - Importers read; one class writes

**Decision.** An importer fills `DayBucket`s. It never touches a rollup table,
never chooses a provenance value, never asks whether a day was imported before,
and never writes to the source it is reading.

**Why.** Three importers each writing their own rows would be three chances to
get provenance, deduplication, path normalisation and the timezone slightly
differently wrong - and the wrongness would only surface years later as a
number nobody could explain.

**Consequence.** Adding a fourth source is a matter of reading a fourth thing.
The rules that are easy to get wrong - hosts never URLs, canonical device
values, calendar dates in the site's timezone, aggregates only and never an
identifier - are enforced in one place and documented in
[`import-architecture.md`](import-architecture.md).

### ADR 57 - The paid reports are named in the free menu, and described

**Decision.** Lite keeps Campaigns, Locations, Events, Goals, Funnels and
Crawlers in the Analytics menu, each marked with a small `Pro` badge, each
leading to a page that says what the report contains. `LockedScreen` is an
ordinary Lite screen, not a Pro one: `isPro()` returns false, so it renders
rather than answering 403. The slugs are the ones the real screens use, so a
bookmark survives the upgrade.

**Why.** This reverses the first arrangement, where the rows were absent
altogether. That was defensible - a menu item leading to an advertisement is
worse than no menu item - but it had a cost nobody had measured: people could
not find out the reports existed. A free edition that conceals the existence of
the paid one is not restraint, it is poor information.

The plugin directory's rule is about **locked code**, not about mentioning that
a paid edition exists. Lite genuinely does not contain these reports; they are
removed by the build (ADR 48). The page is therefore a description of software
somebody does not have, rather than a gate in front of software they do, and it
says exactly that in as many words.

**Consequence.** The pages carry **no figures, real or invented**. A skeleton
with plausible numbers in it would be read as the site's own data, which would
be a lie about the one thing this plugin sells. What they carry is one sentence
on what the report answers, a list of what it actually contains, and a single
link. No price, no button, no countdown, and nothing that returns after being
dismissed, because there is nothing to dismiss. The Dashboard placeholder cards
link to the same pages rather than repeating them.

A build that has the Pro code but no active licence gets the same pages instead
of 403s, which is a better answer for somebody whose licence has lapsed than a
locked door.
