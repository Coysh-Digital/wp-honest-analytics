# Third-party components

Honest Analytics bundles the following. Everything is served from your own
server; nothing is fetched from a CDN or any other third party at runtime.

---

## Chart.js

- **Version** 4.5.1
- **Licence** MIT
- **Home** <https://www.chartjs.org/>
- **Bundled at** `assets/admin/js/vendor/chart.umd.js`
- **Licence text** `assets/admin/js/vendor/LICENSE.chartjs.txt`
- **Provenance** `assets/admin/js/vendor/PROVENANCE.md` records the source and
  the SHA-256 of the committed file. `composer budgets` verifies the checksum,
  so an unexplained change to the file fails the build.

Committed rather than fetched, because a CDN is a third party watching your
admin ([ADR 41](docs/architecture.md#adr-41--chartjs-is-vendored-with-provenance)).

---

## Composer dependencies

Installed into `vendor/` and included in the distributed zip.

### donatj/PhpUserAgent

- **Licence** MIT
- **Home** <https://github.com/donatj/PhpUserAgent>
- **Used for** parsing a user-agent string into browser and platform families.
  The string itself is discarded immediately afterwards.

### jaybizzle/crawler-detect

- **Licence** MIT
- **Home** <https://github.com/JayBizzle/Crawler-Detect>
- **Used for** identifying crawlers, so they are reported separately and never
  counted as people.

### maxmind-db/reader

- **Licence** Apache-2.0
- **Home** <https://github.com/maxmind/MaxMind-DB-Reader-php>
- **Used for** reading a local GeoLite2 or DB-IP database when country
  reporting is enabled. The database is a file you install yourself; the reader
  makes no network request, and no address is retained after the lookup.

---

## Development-only

Not shipped. Present in the repository and in CI.

| | Licence |
|---|---|
| PHPUnit | BSD-3-Clause |
| Yoast PHPUnit Polyfills | BSD-3-Clause |
| PHP_CodeSniffer | BSD-3-Clause |
| WordPress Coding Standards | MIT |
| PHPCompatibility | LGPL-3.0-or-later |
| PHPStan | MIT |
| szepeviktor/phpstan-wordpress | MIT |
| php-stubs/wp-cli-stubs | MIT |
| Playwright | Apache-2.0 |

---

## Geo databases

Neither is bundled. If you enable country reporting you install one yourself
with `wp honest-analytics geo install`.

- **GeoLite2** by MaxMind - Creative Commons Attribution-ShareAlike 4.0, and
  MaxMind's own end-user licence. Attribution is required and is rendered on
  the Locations screen.
- **DB-IP Lite** - Creative Commons Attribution 4.0. Attribution is likewise
  rendered on the Locations screen.
