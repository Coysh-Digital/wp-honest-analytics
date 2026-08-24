# Development

## Getting a site

```bash
cd dev
./setup.sh --seed
```

ddev, WordPress, this repository mounted as the plugin, four hundred days of
demo traffic. Details and the traps in [`dev/README.md`](../dev/README.md).

The repository is mounted **read-only**. `wp plugin uninstall` deletes the
plugin directory, and with a read-write mount that directory is the source
tree. Use `dev/uninstall-test.sh` to exercise the uninstall path without
losing anything.

## Layout

```
honest-analytics.php   header, constants, autoload, activation hooks
uninstall.php          runs only when the plugin is deleted
src/                   PSR-4, HonestAnalytics\
templates/             PHP views, rendered by Admin\Views\View
assets/                shipped as written; there is no build step
bin/                   tooling
tests/                 Unit (no WordPress) and Integration (real WordPress)
docs/                  this
dev/                   the integration runner and the importer fixtures
```

`src/` reading order, if you are new to it:

1. `Bootstrap.php` - every hook the plugin attaches, in one file.
2. `Plugin.php` - the lazy service registry.
3. `Capture/` → `Write/` → `Rollup/` - the pipeline, in the order data moves.
4. `Stats/` → `Admin/Screens/` → `templates/` - the read path.

## Tests

```bash
composer test:unit          # no WordPress, no database, fast
composer cs                 # PHPCS: WPCS plus the security sniffs
composer stan               # PHPStan level 8
composer budgets            # tracker size and vendor checksums
```

Integration tests need a real WordPress and a real MySQL. Both come from the
demo site's ddev project, which is the development harness; `dev/README.md`
says how to build it. One command does the rest, including installing the
WordPress test library if the container's `/tmp` has been emptied by a restart,
which it will have been:

```bash
cd dev
./integration.sh                  # single site
./integration.sh --multisite      # as a network
./integration.sh --filter=Drain   # one class
```

Outside the harness, against your own MySQL:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
composer test:integration
WP_TESTS_MULTISITE=1 composer test:integration
```

One quirk worth knowing before you write an integration test: `WP_UnitTestCase`
rewrites `CREATE TABLE` into `CREATE TEMPORARY TABLE`, so a table created
*during* a test never appears in `SHOW TABLES`. Assert that a table exists by
selecting from it, not by listing tables - `MultisiteTest` has the pattern.

### What the suites are for

**Unit** covers pure logic: date ranges, HLL sketches, path normalisation,
campaign parsing, channel classification, device parsing, bot detection, CSV
defusal, chart payload shape, heatmap bucketing, goal and funnel validation,
the settings sanitizer, the edition gate, and the tracker's size budget.

**Integration** covers everything that touches storage or WordPress: schema
drift, blob round-tripping, upsert arithmetic, the full spool-to-report drain,
compaction, dimension capping, retention coverage, salt rotation, cross-site
isolation, capability and REST permission enforcement, export defusal end to
end, and `NoIpPersistedTest`, which fails the build if an address appears
anywhere.

The three registry tests are worth understanding before adding a table.
`Tables::expiringRollups()`, `Tables::retainedElsewhere()` and
`Tables::dimensionReferences()` must between them account for every table, and
the suite fails if a new one is in none of them. A rollup table that nobody
registered would accumulate forever without anyone noticing.

## Coding standards

PSR-12 layout, WordPress security and database sniffs, PHPStan level 8.

```bash
composer cs
composer cs:fix
composer stan
```

WPCS's file-naming and function-naming sniffs are excluded because the codebase
is namespaced and PSR-4. The security, database and internationalisation sniffs
are not excluded and are not to be excluded.

Two rules with no automated check, both worth stating:

- **Escape at the final output boundary**, including values that came from our
  own tables. A path in `pages_rollup` arrived there from a URL; it is a
  stranger's data that we stored.
- **No SQL string interpolation except table names**, which come from
  `Tables::` and never from a request. Everything else goes through
  `$wpdb->prepare()`.

## Adding a screen

1. `src/Admin/Screens/ThingScreen.php` extending `Screen`.
2. `templates/admin/thing.php`.
3. Register it in `Admin\Menu`, with the capability and - if it is Pro - the
   edition gate. Lite must not register it at all.
4. Reads go through a service in `src/Stats/`, never `$wpdb` in a template.
5. Every chart needs its semantic table fallback. Every table wider than the
   card needs `.ha-scroll`.

## Adding a setting

1. A typed property with a default on `Settings\Settings`.
2. A validation rule in `Settings\Sanitizer` - meaning-specific, not
   `sanitize_text_field()` on a number.
3. A row in the right group in `templates/admin/settings.php`, with help text
   and, if it changes behaviour visibly, an amber "Consequence:" line.
4. A test in `tests/Unit/SanitizerTest.php` for the boundary.

Defaults are the privacy-preserving option in every group. If a new setting's
safe value is not its default, that is a design error, not a documentation
problem.

## Adding a table

1. A constant in `Schema\Tables` and the statement in `Schema\Schema`, in
   dbDelta dialect - dbDelta is fussy about whitespace and will silently do
   nothing if you get it wrong.
2. Bump `Schema::VERSION` and add the migration to `Schema\Upgrader`.
3. Register it in exactly one of the three registries above.
4. Add it to `uninstall.php` implicitly by using `Tables::allTables()`.
5. Run `composer test:integration` - `SchemaTest` asserts dbDelta reports no
   drift on a second run, which catches most dialect mistakes.

## Charts

PHP emits data and semantic tokens. It does not emit Chart.js configuration.

```php
$chart = ChartData::line( $labels, [
	[ 'label' => 'Views',   'data' => $views,   'token' => 'series-1' ],
	[ 'label' => 'Uniques', 'data' => $uniques, 'token' => 'series-2' ],
] );
```

`assets/admin/js/charts.js` resolves tokens against CSS custom properties on
the figure, builds the chart, honours `prefers-reduced-motion`, and reveals the
hidden data table if rendering fails. Four series maximum plus a neutral
"Other". The heatmap is an HTML table, never a canvas.

## Accessibility

```bash
npm install
node bin/accessibility.mjs
```

Runs axe-core at WCAG 2.2 AA over all fifteen screens at 1440px and 390px, then
adds the checks axe cannot make: that every chart has a table carrying the same
numbers, that the heatmap is a table rather than a canvas, that data tables have
scoped headers, that every control is labelled, that Real-time has a live
region, and that a table which scrolls sideways can be scrolled with a keyboard.

It exits non-zero on any finding, so it can be a build step. The target is zero,
and zero is where it is.

## Screenshots

```bash
npm install
node bin/screenshots.mjs
```

Writes `docs/screenshots/{desktop,mobile}/` at 1440×1100 and 390×844, plus
`_report.json` recording each page's status, title, heading and console errors.
Run it against a seeded site, and check the report for console errors before
looking at the images.

## Building a release

Two builds from one source tree:

```bash
bash bin/build-lite.sh    # slug honest-analytics,     for wordpress.org
bash bin/build-pro.sh     # slug honest-analytics-pro, direct download
composer build            # both
```

Each installs production dependencies, stages the tree without development
files, strips vendor test directories, refuses to package if anything
prototype-shaped or any CDN reference is found, stamps
`HONEST_ANALYTICS_HAS_PRO`, and zips.

The Lite build additionally **removes** the Pro source and templates rather
than leaving them behind a licence check - wordpress.org will not take a
package full of paid code, and a user reading the source of a free plugin
should find the free plugin. What is removed is listed in
`bin/pro-manifest.txt`, in three sections a person can read:

| | |
|---|---|
| `strip` | removed from Lite |
| `gated` | survives Lite and legitimately names a stripped class, from behind an edition check |
| `blocked` | ought to be Pro-only, cannot be stripped yet, with the reason |

Two guards run before either zip is written. `bin/check-lite-build.php` derives
a class name from every stripped path and refuses to package if a surviving
file still reaches for one - distinguishing load-time references (`extends`,
trait `use`, constant initialisers) from runtime ones, and knowing that
`Stripped::class` is a compile-time string rather than a reference.
`bin/check-classes-load.php` then autoloads every class in each staged build in
a subprocess, because parsing is not booting.

If you make something Pro-only, add it to the manifest and run the build. The
guard will tell you what still points at it.

## Releasing

1. `composer test:unit && composer test:integration && composer cs && composer stan && composer budgets`
2. Update `CHANGELOG.md`, `readme.txt` (stable tag) and the plugin header
   version - all three, they are checked against each other.
3. `composer build`
4. Install the zip on a clean site and activate it. The zip is what ships, not
   the repository.
