# Vendored front-end code

Everything in this directory is third-party code copied in verbatim. There is no
build step: these files are served exactly as committed.

That makes provenance a manual discipline, so this file records it. Every entry
names the package, the pinned version, where the bytes came from, and the
SHA-256 of the file as committed. `bin/check-budgets.php` re-checks those
hashes, so swapping a vendored file without updating this table is a failing
build rather than a silent change.

## Files

| File | Package | Version | Upstream path | SHA-256 |
|---|---|---|---|---|
| `chart.umd.js` | [chart.js](https://github.com/chartjs/Chart.js) | 4.5.1 | `package/dist/chart.umd.js` | `ecc3cd1eeb8c34d2178e3f59fd63ec5a3d84358c11730af0b9958dc886d7652a` |
| `LICENSE.chartjs.txt` | chart.js | 4.5.1 | `package/LICENSE.md` | - |

## Re-deriving

```sh
curl -sSL -o pkg.tgz https://registry.npmjs.org/chart.js/-/chart.js-4.5.1.tgz
tar xzOf pkg.tgz package/dist/chart.umd.js | shasum -a 256
```

## Notes

**The full UMD bundle, deliberately.** A tree-shaken custom build would save
roughly 20 KB gzipped and cost the one property this directory trades on:
anyone can re-derive the hash above from the published package and check it. A
bundle produced once on a developer's machine cannot be re-derived by anyone,
and with no build step in this repository there would be nothing to reproduce it
from. The 20 KB is the cheaper side of that trade.

**Nothing is loaded from a CDN.** Not this file, not the tracker, not a font.
The whole point of the plugin is that a visitor's browser talks to this site and
to nobody else, and an admin screen pulling a script from somebody else's server
would undermine that claim in a way that is easy to miss.
