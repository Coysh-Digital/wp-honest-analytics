# Handoff

Honest Analytics 0.1.0 - a WordPress port of Craft Analytics, built from the
approved prototype and the reference implementation.

Everything below was run against a real WordPress in ddev with 400 days of
seeded traffic, not asserted from the code.

---

## What is here

| | |
|---|---|
| PHP classes | 216 under `src/`, namespaced `HonestAnalytics\` |
| Templates | 44 under `templates/` |
| Lines of PHP | ~48,000 in `src/` and `templates/` |
| Database tables | 30, versioned and migrated |
| Screens | 17, plus two dashboard widgets, a post-list column and an editor panel |
| Tests | 318 unit + 377 integration = **695** |
| Documentation | 15 files under `docs/` |
| Screenshots | 46 (23 screens × desktop and mobile) |
| Distributables | Lite 724 KB / 378 files, Pro 784 KB / 412 files |

### The pipeline

A public pageview is snapshotted at `wp`, judged at `shutdown` after the
response has been flushed, appended to a spool as one NDJSON line, drained in
transactional batches by cron, and applied to hourly rollups that are compacted
to daily and deleted at 26 months. No raw hit is ever stored. Storage grows with
dimensions × time, not pageviews × time.

Hybrid tracking is the default: the server counts what it sees, a 1.3 KB
first-party tracker confirms the rest, and a nonce consumed **once per visitor**
reconciles the two - which is what makes a full-page cache work without a single
exclusion.

### The screens

Dashboard, Real-time, Pages, Page detail, Content (post types / taxonomies /
authors), Sources, Devices, Privacy and Settings are in Lite, with CSV and JSON
export and every WordPress-native surface. Campaigns, Locations, Events, Goals,
Funnels and Crawlers are Pro, along with consented durable tracking, stored
journeys, scheduled summaries, the Licence screen and the third-party
integrations.

In Lite the Pro screens are not registered at all - a direct URL is a 403, not a
sales page - and the Dashboard shows one muted placeholder card per missing
section saying what it would contain.

### Editions and licensing

Three editions, two builds. Lite ships to wordpress.org with the Pro source
**removed**, not disabled; Pro and Agency are the same direct-download build,
and the licence decides only how many sites may use it. The licence is a
one-off purchase with no expiry: support lapses after twelve months and
withdraws nothing, and a provider outage keeps the last known good state.

The provider is not yet chosen and nothing hard-codes one - see
[`editions.md`](editions.md) for the whole model, including what is still TBC.

### Integrations

Contact Form 7, Gravity Forms, WooCommerce, WPForms and Ninja Forms, behind one
interface and a registry. Event handles are stable - `form:cf7-5` - so renaming
a form neither breaks a goal nor splits it into two rows, and the readable label
is resolved by asking the plugin what the form is called now.

### Importing

**Analytics → Import data** brings history across from WP Statistics,
Independent Analytics and Google Analytics 4. In the free edition, all three:
charging somebody to bring their own history across would be a strange way to
make switching feel easy.

A wizard rather than a database utility. Nobody has to know about tables,
OAuth, pagination, cron, schemas, cursors or GA4 dimensions. Every import shows
what was found, states plainly that different tools count differently, and
marks each metric as an exact match or an approximation with the reason.

Imports are **idempotent by construction** - a day is written whole, replacing
whatever that source wrote for it before - **resumable** from a date cursor, and
**never silently double-counting**: overlapping sources are detected and the
safe option is the default. The source is strictly read-only throughout.

See [`importing.md`](importing.md) for the guides and
[`import-architecture.md`](import-architecture.md) for the design.

### Everything else

Dedicated tables with `dbDelta` migrations; three custom capabilities checked at
every boundary; REST collect/consent/realtime routes; nine WP-CLI commands;
CSV and JSON export with formula defusal; suggested privacy-policy text and the
personal-data exporter and eraser; per-site multisite tables; Contact Form 7,
Gravity Forms and WooCommerce event integrations; a seeder that creates demo
content and 400 days of realistic traffic.

---

## Decisions worth knowing

The full set is fifty numbered entries in [`architecture.md`](architecture.md).
These are the ones that shape everything else.

**An address is never written anywhere** - not to a table, a log, a cache key,
the spool or a queue. It is hashed inside one function and discarded.
`tests/Integration/NoIpPersistedTest.php` drives a request with a known address
and then searches every table, the key-value rows, the spool file and the debug
log for it.

**The daily salt is overwritten in place**, so yesterday's hashes cannot be
recomputed by anybody holding the database. That is what makes "unique visitors"
a *daily* number, which is stated on every screen that shows one.

**The dedupe nonce is keyed on (nonce, visitor)**, not on the nonce alone. One
piece of cached HTML served to a thousand people counts a thousand times; one
visitor reloading counts once. Both halves have tests.

**Counting happens after `fastcgi_finish_request()`**, so analytics is never in
the critical path of a page load.

**The collect endpoint always answers 204 with an empty body** - accepted,
rejected, deduplicated or rate-limited alike - so it cannot be used as an
oracle.

**GPC is honoured absolutely and by default**: no count, no spool line, no
tracker injected.

**Server-rendered PHP, not a React admin.** Every chart carries a semantic table
with the same numbers, revealed if the chart fails; the heatmap is an HTML table,
never a canvas.

**Chart.js is committed with its checksum.** The plugin makes no outbound
request to any third party under any circumstance - the only network calls it
ever makes are the two daily loopbacks to the site itself.

---

## What was run

| Check | Result |
|---|---|
| Unit suite | **316 tests, 697 assertions - pass** |
| Integration, single site | **364 tests, 969 assertions - pass** (7 skipped) |
| PHPCS (WPCS + security/DB/i18n + PHPCompatibility 8.1−) | **clean** |
| PHPStan level 6 | **clean** |
| Asset budgets and vendor checksums | **pass** - tracker 1,307 / 2,048 bytes gzipped |
| axe-core, WCAG 2.2 AA, 17 screens × 2 widths | **0 findings** |
| Screenshots | 23 screens × 2 widths, no console errors except one Gutenberg blob request |
| Builds | both zips: no dev files, no prototype runtime, no CDN reference, every class loads |

### Verified by hand against a live site

- Activation creates 26 tables, three capabilities, three cron events and the
  protected spool directory.
- A real pageview reaches the rollups: capture → spool → drain → report.
- Hybrid dedupe: same visitor + same nonce = 1 view; different visitor + same
  nonce = 2 views.
- `Sec-GPC: 1` produces no spool line, no row and no injected tracker, while
  ordinary visitors in the same minute are counted.
- No IP-shaped string exists in any of the 26 tables after 227,000 hits.
- REST permissions: `/realtime` 401 unauthenticated, export 400 without a nonce,
  `/collect` 204 with a zero-byte body and `no-store`.
- Upgrade path: version reset to 0 with a table dropped self-heals on the next
  request, with existing rows untouched.
- Uninstall drops all 26 tables, the options, the cron events and the spool;
  with **Keep data on uninstall** on, all 71,064 rows survive.
- Lite hides the five Pro screens from the menu and 403s a direct URL.
- Multisite: per-site tables, cross-site isolation, site deletion collects the
  right table names.
- Seeding 227,180 hits from 86,374 visitors across 400 days takes ~3 minutes;
  `gc` then compacted 800 days.
- `gc --dry-run` counted 222,659 rows a one-month retention would delete, and
  deleted none of them.
- The **Lite zip was installed into WordPress and activated**: every Lite screen
  renders, all six Pro URLs 403, and it read the same 71,064 rows the Pro build
  had written - the upgrade-and-downgrade claim, checked rather than asserted.
- Activating both builds at once resolves to Pro on the next admin request,
  with the other deactivated, no data touched and no constant redefined.
- **A WP Statistics import was run end to end on the live site**, against real
  source tables built from the fixtures: detection, preview, 32 rows across
  five rollup tables, paths normalised and merged, Unicode decoded, campaign
  parameters stripped and search terms kept. Running it a second time left the
  figures exactly where they were - 9 rows, 377 views, 4 covered days.

### Two production defects the tests found

**Every 404 threw a `TypeError` instead of declining.** The decline reasons were
an array keyed by slug, and PHP casts the key `'404'` to an integer whatever the
quotes say, so it arrived at `decline( string $reason )` as `int(404)` under
`strict_types`. Swallowed by the try/catch upstream, so nothing visibly broke -
the guard simply never returned its verdict.

**The database write queue had no bound at all**, on exactly the hosts that
driver exists for. It now has back-pressure, and the check costs one cache read
rather than the three extra queries per write that the first version cost.

A third, found by static analysis: the Internet Explorer user-agent alternation
lost its version, because on an `rv:` match group 1 is set-but-empty rather than
absent, so the `??` fallback never fired.

---

## Limitations

Nothing here is hidden in a footnote elsewhere.

**Beacon-only views carry no post ID.** The tracker does not send one and
resolving a path to a post per beacon is too expensive. `DbRollupSink` and the
compactor fill it in from any server-side view of the same path, so hybrid sites
are fine - but a site so thoroughly cached that PHP never renders a page will
under-report the per-post Views column.

**A replayed beacon is counted.** `NonceRegistry::claim()` is a conditional
delete, so if one visitor's beacon fires twice for a single pageview the second
finds nothing to claim. Recognising replays would mean keeping every spent nonce
for its full lifetime - a store that grows with traffic. The trade is written
into the code.

**Segments, GraphQL and a map on Locations are not built** (ADR 49-51). The
dimension tables are shaped to accept segments later.

**Geo needs a database you install yourself.** GeoLite2 and DB-IP are not
bundled, for licensing reasons. The Locations screen installs one by upload or
by HTTPS address, and `wp honest-analytics geo install` does the same from a
terminal.

**Licence validation is offline only.** `LicenceValidatorInterface` is the
extension point for remote activation; nothing calls home today.

**`Upsert::counters()` writes `0` rather than SQL `NULL`** if a caller passes an
explicit `null` in `$extra`, because `$wpdb->prepare()` renders it as an empty
string. No current caller does, and `DbRollupSink` omits the key instead - but a
future one using `fillIfNull` that way would get silently wrong behaviour.

**The importers are tested against fixtures, not the real plugins.** Neither WP
Statistics nor Independent Analytics is installed here, so both importers were
written against `information_schema` lookups rather than assumed schemas, and
exercised against real tables built from fixtures in two shapes each. A layout
neither shape covers reports itself as unsupported in a sentence rather than
fataling - but the first genuine test is a staging site with real data from
both. The same goes double for GA4: every part of it runs against recorded
responses, and the broker contract, real quota behaviour and whether
`bounceRate` and `userEngagementDuration` keep those names are all unverified
against a live Google account.

**The integrations are tested against doubles, not the real plugins.** Every
hook, payload shape and method name was read from those plugins' documentation
and source, and the suite drives them through fakes - but Contact Form 7,
Gravity Forms, WooCommerce, WPForms and Ninja Forms have not been installed and
submitted for real. That is the first thing to do with a staging site.

**An order in a currency other than the store's own is counted without its
value.** The events table has one decimal column and no currency beside it, so
adding euros to pounds would produce a confident wrong number. The event is
recorded, the value is not, and the reason is logged.

**Not exercised end to end:** the scheduled summary email refuses correctly with
no recipients configured but has not been delivered to a mailbox; the CI
workflow is written but has never run, because there is no remote; and the two
builds were verified by installing the Lite zip into the development site rather
than onto a clean WordPress.

**The chart marker is a caption, not a line on the canvas.** Where imported
history meets native data, the traffic chart carries a muted line beneath it
naming the boundary date and source. A canvas annotation would vanish in the
table fallback and say nothing to a screen reader; a caption survives both. The
vertical rule is a small addition to `charts.js` if it is wanted as well.

**Four things are Pro-only but not yet stripped from the free build** -
`src/Goals/`, `src/Consent/`, `ProStatsService`, `ProRollupWriter` and
`JourneyRecorder`. They are reachable from screens that ship in Lite, behind
edition ternaries, so stripping them safely needs a call-site audit rather than
one gate. The saving is small; the reason is written down in
`bin/pro-manifest.txt` under `[blocked]` rather than left to be rediscovered.

**The nginx spool rule is the operator's job.** `.htaccess` and `web.config`
are written at activation and do nothing on nginx. The daily loopback check
detects the exposure and says so on the Settings screen - it found it on this
very harness, which is why `dev/setup.sh` now writes the rule.

---

## Commands

### Getting a site

```bash
cd dev
./setup.sh --seed          # ddev WordPress, the plugin active, 400 days of traffic
```

Site: <https://honest-analytics-dev.ddev.site> · admin / admin.
The repository is mounted **read-only**, deliberately.

### Activation

```bash
wp plugin activate honest-analytics
wp honest-analytics info                 # stores, drivers, backlog, health
```

### Cron

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
*/5 * * * * cd /path/to/wordpress && wp honest-analytics drain --quiet
0   4 * * * cd /path/to/wordpress && wp honest-analytics gc --quiet
```

One line for a whole network:

```cron
*/5 * * * * cd /path/to/wordpress && wp honest-analytics drain --network --quiet
```

### Seeding

```bash
wp honest-analytics seed --days=400 --per-day=520 --content --force
wp honest-analytics gc
```

`--content` creates demo posts, pages, categories, tags and authors when the
site has none, so the Content screens have something to say. Everything it
creates carries the `_honest_analytics_demo` meta key.

### Tests and checks

```bash
composer test:unit          # 171 tests, no WordPress, no database
composer cs                 # PHPCS
composer stan               # PHPStan level 6
composer budgets            # tracker size, vendor checksums, forbidden APIs

cd dev
./integration.sh            # 262 tests against a real WordPress
./integration.sh --multisite
./integration.sh --filter=DrainTest
```

Outside the harness:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
composer test:integration
```

### Visual and accessibility QA

```bash
npm install
HA_URL=https://honest-analytics-dev.ddev.site node bin/screenshots.mjs
HA_URL=https://honest-analytics-dev.ddev.site node bin/accessibility.mjs
```

Both exit non-zero on a finding.

### Building a release

```bash
bash bin/build-lite.sh      # build/honest-analytics-0.1.0.zip      - wordpress.org
bash bin/build-pro.sh       # build/honest-analytics-pro-0.1.0.zip  - direct download
composer build              # both
```

The Lite build removes the Pro source rather than gating it, and refuses to
package if anything left behind still reaches for something it removed.

### Testing uninstall without losing your source

```bash
cd dev && ./uninstall-test.sh
```

`wp plugin uninstall` deletes the plugin *directory*. In a harness that mounts
your working tree, that is your working tree. Do not run it.

---

## Screenshots

`docs/screenshots/desktop/` and `docs/screenshots/mobile/`, at 1440×1100 and
390×844, with `docs/screenshots/_report.json` recording each page's status,
title, heading and console errors.

| | |
|---|---|
| `01-dashboard.png` | KPI strip, traffic chart, heatmap, top pages, channels, devices, post types, Pro cards, crawlers |
| `02-realtime.png` | Active sessions, live region, 15-second polling |
| `03-pages.png` | Ranked table, filters, comparison checkboxes |
| `04-page-detail.png` | One page's KPIs, trend, how visitors reached it |
| `05-sources.png` | Channel mix over time, channels, referring hosts |
| `06-campaigns.png` | Pro |
| `07-content.png` | By post type |
| `08-devices.png` | Doughnut, browsers, operating systems |
| `09-locations.png` | Pro |
| `10-events.png` | Pro |
| `11-goals.png` | Pro |
| `12-funnels.png` | Pro |
| `13-crawlers.png` | Separate from every other figure |
| `14-privacy.png` | Posture, stored / not stored, identifiers, lawful basis, subject access |
| `15-settings.png` | Every group, with its consequences |
| `16-licence.png` | Key, status, and what the licence actually is |
| `17-content-taxonomies.png`, `18-content-authors.png` | The other Content tabs |
| `19-pages-compare.png` | A bookmarkable comparison |
| `20-dashboard-90d.png` | A wider range |
| `21-dashboard-widgets.png` | Overview and Right now on the WordPress dashboard |
| `22-posts-column.png` | Views on the post list |
| `23-post-editor.png` | The analytics panel |

---

## Where to start reading

1. [`architecture.md`](architecture.md) - fifty-six decisions and why.
2. `src/Bootstrap.php` - every hook in one file.
3. `src/Capture/` → `src/Write/` → `src/Rollup/` - the pipeline, in order.
4. [`editions.md`](editions.md) - Lite, Pro and Agency, and the open decisions.
5. [`craft-to-wordpress-mapping.md`](craft-to-wordpress-mapping.md) - what
   became what, and what deliberately did not.
