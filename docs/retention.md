# Retention

Data ages in three stages: hourly detail, daily rollups, then deletion. Every
stage is a setting, every setting has a cap, and the caps are enforced in code.

## The stages

| Age | State |
|---|---|
| 0 to `hourlyWindowDays` (default 7) | One row per hour per dimension |
| Up to `rollupRetentionMonths` (default 36, max 36) | Folded to one row per day (`hour = -1`) |
| Beyond that | Deleted |

Compaction is lossless for counts and merge-correct for uniqueness sketches. It
reads the existing daily row back before merging, so running it twice changes
nothing. Measured on a seeded dataset: 21,156 hourly rows folded to 3,467 daily
rows with views and unique visitors identical before and after.

## Everything with a retention setting

| Data | Setting | Default | Cap |
|---|---|---|---|
| Hourly detail | `hourlyWindowDays` | 7 days | - |
| All rollups | `rollupRetentionMonths` | 36 months | 36 months |
| Journeys (Pro, consented) | `journeyRetentionDays` | 90 days | 790 days |
| Consent records | `consentLogRetentionDays` | 0 = keep | - |
| Sessions | `sessionWindow` | 30 minutes idle | - |
| Dedupe nonces | `nonceTtl` | 30 minutes | - |
| Rate-limit counters | - | 1 minute | - |
| Identity salt | `saltRotationInterval` | 24 hours | - |
| Report cache | - | 1 hour, never today | - |

Consent records default to being kept indefinitely on purpose: a consent record
is the evidence that consent was given, and deleting it destroys the ability to
demonstrate compliance. Set a number of days if your policy requires one.

Thirty-six months is the cap because it is the outer limit most data protection
guidance treats as proportionate for analytics, and because a hard limit that
cannot be edited away is worth more than a default that can.

Rows brought in by an import are exempt from this window entirely, on every
table that carries a `source` column - `GcService` deletes only
`source = 'native'` rows past the cutoff there. History somebody imported is
kept for as long as the import itself is, which is indefinitely, regardless of
what `rollupRetentionMonths` is set to. See
[`import-architecture.md`](import-architecture.md).

## Growth

Storage grows with **dimensions × time**, not with **pageviews × time**. A site
with a hundred thousand views a day and a site with a hundred use approximately
the same amount of disk, because both write one row per hour per dimension
value.

Measured on a seeded install: about 110,000 events, 60 distinct dimension
values, 26 tables - roughly 15 MB, with no journey rows at all.

The bound is `dimensionCap` (default 1000). Each dimension accepts the first
1000 distinct values it sees in a period; everything after that is bucketed as
`__other__`. First-N rather than top-N, because top-N would require storing
everything first
([ADR 27](architecture.md#adr-27--dimension-cardinality-is-capped-first-n-not-top-n)).
Screens affected by the cap say so in a footnote and render "Other" as a muted,
non-clickable row.

## Garbage collection

Runs daily on `honest_analytics_gc`, or on demand:

```bash
wp honest-analytics gc
wp honest-analytics gc --dry-run
```

In order: compact hourly rows past the window; delete rollups past retention;
delete journeys past retention; delete consent records past retention if a
retention is set; close sessions idle beyond the window; delete expired
key-value rows; remove dimension values no longer referenced by any rollup.

The last step is why `Tables::dimensionReferences()` exists, and why an
integration test asserts that every table holding a dimension reference is in
that list. A new rollup table added without registering it there would leave
orphaned dimension rows accumulating forever, and the test fails rather than
letting that happen quietly.

`Tables::expiringRollups()` and `Tables::retainedElsewhere()` have the same
role for the retention sweep, with the same style of test: every table is in
exactly one of the three registries, and the suite fails if a new one is in
none.

## Changing retention

Shortening it deletes data at the next garbage collection. That is immediate
and irreversible - export first if you want it:

```bash
wp honest-analytics gc --dry-run   # says what would go
```

Lengthening it does not bring anything back.
