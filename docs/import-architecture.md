# Importing: how it works

The internal document. What was inspected, what was decided, and why. The
user-facing guides are in [`importing.md`](importing.md).

---

## Phase 1: what the destination actually is

Before designing anything, the questions worth answering about this plugin.

| | |
|---|---|
| **Storage model** | Aggregate rollups. There is no table of hits, no visitor table and no session history beyond the live window. |
| **Grain** | One row per (site, date, hour, dimension). `hour = -1` is a daily row - what the compactor leaves behind after a week. |
| **Tables** | 26, `{$wpdb->prefix}honest_*`. Six of them hold anything an import could write. |
| **Migrations** | `Schema::VERSION` plus `Installer::widenBucketKeys()` for anything dbDelta cannot do, run by `Upgrader` from cron, from the CLI bootstrap and from the Settings tidy-up button - never from a page load (ADR 58). The drain stands down while one is outstanding. |
| **Background work** | WP-Cron, a five-minute drain, and a traffic-triggered auto-drain. No queue library. |
| **Admin** | Server-rendered PHP templates, `.ha-` CSS, WordPress-native components, no framework. |
| **REST** | `honest-analytics/v1`, capability plus `wp_rest` nonce, permission callbacks on everything non-public. |
| **JavaScript** | Three small vanilla scripts. No build step. |
| **Dates** | Calendar days in the site's timezone, `Support\Timezone::site()`. |
| **Paths** | `Capture\PathNormalizer` - campaign parameters stripped, noise parameters removed, one trailing slash, decoded. |
| **Visitors** | A 16-character hash of a salt destroyed every 24 hours. Counted with HyperLogLog sketches, merged never summed. |
| **Provenance** | None, before this. That was the first thing to fix. |
| **Onboarding** | Notices and the Settings screen. No wizard existed. |

Two consequences shaped everything after.

**The destination is already aggregate and already daily.** An import does not
have to invent a shape: a day of imported analytics is the same row a day of
compacted native analytics is. That is why `DayBucket` is the internal format
and why nothing is imported hourly.

**Visitors are a sketch, not a number.** Which is a problem, because every
source being imported reports a number.

## Provenance: a column in the key

`source varchar(24) NOT NULL default 'native'`, added to the six tables an
import writes and **to their unique keys**.

Native and imported figures for the same day are therefore different rows. They
can be counted separately, explained separately and removed separately, and an
import can never quietly add itself to a native total. Whether the two should
be added when a range covers both is a question the overlap check asks the user
before the import starts - not a question the schema answers by accident.

The six tables: `pages_rollup`, `pagesources_rollup`, `sessions_rollup`,
`sources_rollup`, `devices_rollup`, `geo_rollup`.

Three more tables carry the import system itself: `honest_imports` (one row per
job, with its cursor and progress), `honest_import_coverage` (one row per
imported day, which is what makes "already imported" an indexed lookup) and
`honest_import_log`.

The migration widens the unique keys with an explicit `ALTER`, before dbDelta
runs, because **dbDelta adds a missing index and never changes an existing
one**. Left to dbDelta, the old four-column key would have survived and two
rows that should have been separate would have merged silently. Verified by
migrating a live table of 71,000 rows in place.

## Visitors: added, not merged

`importedUniques int(11)` sits beside the sketch on `pages_rollup` and
`sessions_rollup`. The read path adds it to the sketch estimate.

The alternative was to synthesise a sketch: take "412 visitors on 3 March" and
add 412 invented tokens to an HLL so that it estimates 412. It would have
worked, and it would have needed no change to any query. It was rejected
because **a sketch of invented identities is a lie that merges convincingly** -
and because it would have cost a hash per visitor per row, which on four years
of history is billions of operations to produce a number the source already
told us.

Adding is also correct rather than merely cheap. Uniques here are *daily*: the
salt is destroyed every night, so two native days already sum rather than
deduplicate. An imported day and a native day sum for exactly the same reason.

## Idempotency: whole days, replaced

`ImportSink::write()` deletes every row that source wrote for that date, then
inserts the day, in a transaction.

Running an import twice replaces the first run rather than doubling it. That is
the property that matters most - an import that doubles somebody's history on a
retry is worse than one that fails outright - and it is far easier to be sure
of than a pile of incremental upserts, each of which would have to get its
arithmetic right on every retry path.

Coverage is recorded per day, so a second import knows what the first covered
without reading a rollup table.

## The shape of an importer

```
ImporterInterface
├── detect()        cheap, read-only, runs on every screen load
├── inspect()       the preview: range, approximate totals, what maps
├── mappings()      metric by metric, marked exact or approximate
├── notes()         what must be said before this particular import
├── start()         the opening cursor
├── processBatch()  a few seconds of work, resumable from its own cursor
└── cleanUp()
```

An importer reads its source and fills `DayBucket`s. It never touches a rollup
table, never chooses a provenance value, never asks whether a day was imported
before, and never writes to the source it is reading. Those are the runner's and
the sink's problems - deliberately, so that adding a fourth source is a matter
of reading a fourth thing rather than getting five shared concerns right for a
fourth time.

`detect()` runs whenever the import screen is opened, so it must be cheap, and
it must not assume the source plugin is active. People deactivate the old one
and leave its tables behind; that is the case most worth handling well.

## Mapping, and where it is approximate

`MetricMapping` carries `EXACT` or `APPROXIMATE` and a one-sentence reason. The
interface reads that rather than hard-coding warnings, so a mapping that gets
more honest in code gets more honest on screen.

| Source | Source metric | Becomes | |
|---|---|---|---|
| WP Statistics | page views | page views | exact |
| | visits | sessions | approximate |
| | visitors | visitors | approximate |
| Independent Analytics | views | page views | exact |
| | sessions | sessions | approximate |
| | visitors | visitors | approximate |
| GA4 | `screenPageViews` | page views | exact |
| | `sessions` | sessions | approximate |
| | `activeUsers` | visitors | approximate |

Every "visitors" row is approximate for the same reason: this plugin's visitor
is a hash of a salt that is destroyed every twenty-four hours, and no other
system means that by the word. GA4's is the furthest away, which is why its
note is the most explicit.

## Rules every importer obeys

**Read-only toward the source.** Never `DROP`, `ALTER`, `TRUNCATE`, `DELETE` or
`UPDATE` another plugin's tables. Deactivating or deleting the old plugin later
does not remove what was imported, and importing does not remove what the old
plugin holds.

**Inspect the real schema.** Both local sources have changed their tables across
versions. Read `information_schema`, map what is there, skip what is not, and
report an unsupported layout as a sentence rather than a fatal.

**Paths through `PathNormalizer`.** The same normalisation native data gets, so
imported and native rows land together instead of forming two entries for one
page - while genuinely different pages stay apart.

**Hosts, never URLs.** Referrers become a channel and a host. ADR 10 applies to
imported data exactly as it applies to native data.

**Canonical values.** Two-letter uppercase country codes or nothing. One
spelling of mobile. `DeviceType` and browser and OS families, not free text.

**Dates deliberately.** A source that stores a calendar date keeps the date it
meant. A source that stores a timestamp is converted once, in a documented
direction. A timezone bug that slides a year of history into the neighbouring
day is the failure mode this rule exists to prevent.

**Aggregates only.** No addresses, no hashes, no user ids, no raw identifiers.
The point is historical reporting, not reproducing old tracking identities -
and an import that increased the amount of personal data on the site would be a
strange feature for this plugin to have.

## Retention does not apply

The garbage collector deletes rollups past `rollupRetentionMonths`, but only
the native ones: `Tables::sourcedRollups()` lists every table that carries a
`source` column, and on those the nightly sweep's `WHERE` clause adds
`AND source = 'native'`. Importing eight years of history onto a site keeping
thirty-six months of its own measurements keeps all eight years - retention is
a promise about what this plugin measures itself, not about history brought in
from elsewhere. Reversing that would mean an import silently losing most of
what it just brought across the first time cron ran.

`honest_searchconsole_rollup` is the exception to the mechanism rather than to
the rule. It is written by `GscRollupWriter` rather than by `ImportSink`, and it
has no `source` column to be spared by - every row in it is imported, because
nothing native writes it. So it is exempt whole, named in
`Tables::retainedElsewhere()` with the reason, rather than carrying a column
that would read `gsc` on every row. Until that was noticed it sat in
`expiringRollups()` with no exemption at all, and the nightly sweep deleted
imported Search Console history the moment it passed the site's own window -
the precise failure this section says cannot happen.

The importer says so before the import starts, when the discovered range
reaches further back than `rollupRetentionMonths` would otherwise keep: each
`ImporterInterface::inspect()` appends a note once it knows the earliest date,
comparing it against today minus the configured retention. Compaction has to
respect the same boundary or the point is moot - `Compactor::groupColumns()`
includes `source` in its grouping key on every table that has one, so a day
inside the hourly window that holds both a native row and an already-imported
one folds to two rows, not one that quietly forgets which was which.

## Editions

Importing is in the free edition, all three sources. Charging somebody to bring
their own history across would be a strange way to make switching feel easy.
