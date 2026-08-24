# Honest Analytics

Privacy-first, cookieless analytics for WordPress. No third-party service, no
addresses, no per-visitor rows.

A WordPress port of [Craft Analytics][craft], built by
[Coysh Digital](https://coysh.digital).

[craft]: https://github.com/coysh-digital/craft-analytics

---

## What it does

Counts your traffic - pages, sources, devices, content, campaigns, goals - and
shows it in the WordPress admin, without sending anything anywhere and without
storing anything that could identify a visitor later.

It works behind full-page caches. It works with content blockers. It works
without JavaScript, in a reduced form. It works without paying for anything.

## What it does not do

- Call a third party while it counts. Not for analytics, not for fonts, not
  for map tiles, not for telemetry. The only outbound calls in the plugin are
  ones you start: importing your history from Google Analytics, or fetching a
  geo database from an address you type.
- Store an address. Not in a table, not in a log, not in a cache key, not in
  the write spool.
- Store a full referrer URL or a full user-agent string. Both are reduced in
  the request that saw them - to an origin, and to four device families - so
  neither reaches the spool either.
- Store a raw pageview. A hit waits in the write spool for a few minutes and is
  folded into counters; nothing keeps one afterwards.
- Set a cookie, unless you explicitly turn on a consented feature that needs
  one.
- Count a visitor who sent `Sec-GPC: 1`.

## How it counts people without identifying them

A visitor is a 16-character hash of a random daily salt, the address, the
user agent and the site. The salt is **overwritten in place** every 24 hours,
so yesterday's hashes cannot be recomputed by anyone - including someone
holding the database and the address.

Uniqueness is estimated from a fixed-size HyperLogLog sketch on the rollup row,
accurate to about ±1.6%. The sketch cannot be asked whether it contains a
particular person; it holds no identifiers.

This is why "unique visitors" means **daily** uniques, and why the interface
says so everywhere the number appears.

## How it survives a page cache

The server counts requests it sees. A 1.8 KB first-party tracker confirms the
rest. A nonce reconciles the two - consumed once **per visitor**, not once per
nonce, so one piece of cached HTML served to a thousand people counts a
thousand times, and one visitor reloading counts once.

No cache exclusions, no hole punching, no cache-plugin add-on.

## Storage

Growth is **dimensions × time**, not **pageviews × time**. A site with a
hundred thousand views a day and one with a hundred use roughly the same disk,
because both write one row per hour per dimension. Around 110,000 events fit in
about 15 MB.

## Screens

Dashboard · Real-time · Pages · Page detail · Content · Sources · Devices ·
Crawlers · Privacy · Settings - all in Lite.

Campaigns · Locations · Events · Goals · Funnels · consented durable tracking ·
stored journeys · scheduled email summaries · form and commerce integrations -
Pro.

Lite is a real product. A privacy-first analytics plugin that will not tell you
your top pages without payment is not a privacy-first analytics plugin.

## Install

WordPress 6.4+, PHP 8.1+, MySQL 5.7+ or MariaDB 10.4+.

The free edition is distributed on wordpress.org: **Plugins → Add New**, search
for **Honest Analytics**, **Install Now**, **Activate**. The paid edition is a
direct download, installed through **Plugins → Add New → Upload Plugin**.

Either way, activate and visit **Analytics**. Then read
[`docs/cron.md`](docs/cron.md), because a cached site needs a real cron
schedule for the aggregation to keep up.

Full instructions: [`docs/installation.md`](docs/installation.md).

## Documentation

| | |
|---|---|
| [Installation](docs/installation.md) | Requirements, activation, first run, multisite |
| [Scheduling](docs/cron.md) | WP-Cron, auto-drain, real cron, health checks |
| [Page caches](docs/caching.md) | How cached pages are counted, what to check |
| [Privacy](docs/privacy.md) | What is stored, what is not, and how to verify it |
| [Importing](docs/importing.md) | Bringing history across from WP Statistics, Independent Analytics or GA4 |
| [Retention](docs/retention.md) | Compaction, caps, growth, garbage collection |
| [Uninstalling](docs/uninstall.md) | What deleting removes, and how to keep it |
| [Architecture](docs/architecture.md) | Fifty-seven numbered decisions and why |
| [Craft → WordPress](docs/craft-to-wordpress-mapping.md) | What became what |
| [Import internals](docs/import-architecture.md) | How the importers work, and what was decided |
| [Development](docs/development.md) | Harness, tests, standards, releasing |
| [Releasing](docs/releasing.md) | The two repositories, and how they stay in line |
| [Editions and licensing](docs/editions.md) | Lite, Pro and Agency; what is in each and what is still TBC |
| [Handoff](docs/handoff.md) | What was built, what was verified, what is not done |

## WP-CLI

Optional, every one of them. Nothing the plugin needs doing requires a terminal:
the geo database installs from the Locations screen, maintenance runs from
buttons on Settings, and counting works on hosts with no cron at all. WP-CLI is
there for people who prefer it and for scripting.

```bash
wp honest-analytics info
wp honest-analytics drain [--retry] [--watch] [--network] [--quiet]
wp honest-analytics gc [--dry-run] [--quiet]
wp honest-analytics salt rotate
wp honest-analytics salt status
wp honest-analytics geo install --file=<path> | --url=<url>
wp honest-analytics geo status
wp honest-analytics privacy posture
wp honest-analytics privacy export --user-id=<id> [--format=json]
wp honest-analytics privacy erase --visitor-id=<hash>
wp honest-analytics report [<kind>] [--range=30d] [--limit=20] [--format=csv] [--email]
wp honest-analytics seed --days=400 --per-day=520 --content --force
```

## Checking the claims

```bash
# The write spool, before aggregation. No address, no user agent, and a
# referrer reduced to its origin.
cat wp-content/uploads/honest-analytics/spool/*.ndjson | head

# Rotate the salt, and watch every identity cease to exist.
wp honest-analytics salt rotate
```

`tests/Integration/NoIpPersistedTest.php` drives a request with a known address
and then searches every table, the key-value store, the spool and the debug log
for it. It fails the build if it finds it.

## Licence

GPL-2.0-or-later. See [LICENSE.md](LICENSE.md) and
[THIRD-PARTY-LICENSES.md](THIRD-PARTY-LICENSES.md).
