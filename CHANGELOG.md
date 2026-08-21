# Changelog

All notable changes to Honest Analytics are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-08-21

A fixes release. The Google Analytics import could not be connected at all, and
several things that needed a terminal no longer do.

### Fixed

- **Connecting to Google Analytics did nothing.** The step that hands the
  browser to Google's sign-in screen went through the redirect helper that
  refuses any address but this site's, so it silently substituted the
  dashboard. Pressing Connect landed on `/wp-admin` with nothing said.
- **Every outcome of that connection was silent.** The result was put in the
  URL and nothing read it, so a cancelled sign-in, a misconfigured Google
  project and a successful connection all looked identical. Each now says what
  happened, and returns to the step it happened on.
- **The free build could warn on every Analytics page.** Composer's optimised
  class map still named the files the packaging removes, so looking one up
  logged two PHP warnings before correctly concluding it was absent. The
  autoloader is now regenerated from the packaged tree, and the build refuses
  to ship a map that names something it removed.
- **The import offer would not go away.** It appeared whenever another
  analytics plugin was installed, whether or not its history had already been
  brought across.

### Added

- **The paid reports keep their place in the free menu**, marked, each leading
  to a page saying what that report contains: what it answers, what it holds,
  and one link. No figures are shown, real or invented, and nothing has to be
  dismissed. See ADR 57.
- **Setting up Google Analytics is written down.** The connect screen is a
  five-step guide in plain English with the redirect address copyable at the
  top, and `docs/importing.md` covers the whole Google Cloud side, including
  the unverified-app warning and why a plain `http://` site cannot complete the
  sign-in.
- **The geo database installs from the Locations screen** by upload or by
  HTTPS address, and can be replaced or removed there. WP-CLI still does it for
  anybody who prefers a terminal.
- **Maintenance runs from Settings.** Drain now, Run the tidy-up, and Retry
  set-aside batches when there is something to retry.

### Changed

- Every message that named a WP-CLI command now names the button as well.
  Nothing the plugin needs doing requires a terminal.

## [0.1.0] - 2026-08-21

First public release. A 0.x number on purpose: everything below works and is
tested, but it has not yet been through a season of other people's hosting,
other people's themes and other people's traffic. 1.0.0 is for when it has.

A WordPress port of Craft Analytics, with the privacy guarantees, metric
definitions and report calculations carried over intact.

### Added

**Collection**

- Server, client and hybrid tracking modes, hybrid by default.
- Cookieless daily identity from a salt that is overwritten in place every
  24 hours.
- Nonce deduplication keyed on visitor as well as nonce, so full-page caches
  are counted correctly without exclusions.
- First-party REST collection endpoint, with a rewrite fallback for sites that
  disable the REST API.
- `Sec-GPC` honoured absolutely and by default; optional Do Not Track.
- Crawler detection and separate crawler reporting.
- Proxy-aware address resolution that does not trust forwarded headers by
  default.

**Pipeline**

- Write spool in uploads with a database queue fallback, claimed by rename and
  drained in transactional batches.
- WP-Cron five-minute drain, traffic-triggered auto-drain, and a documented
  real-cron path. Cron is optional throughout: counting, the nightly tidy-up,
  salt rotation and imports all know how to run without it.
- Hourly rollups compacted to daily, dimension cardinality capping, retention
  sweeps and garbage collection.
- Object-cache acceleration when available, with database implementations of
  every store so nothing is required.

**Reports**

- Dashboard, Real-time, Pages, Page detail, Content, Sources, Devices and
  Crawlers.
- Campaigns, Locations, Events, Goals and Funnels in Pro.
- CSV and JSON export from every screen, protected against spreadsheet formula
  injection.
- Charts through one central module with semantic table fallbacks, an HTML
  heatmap, and `prefers-reduced-motion` support.

**WordPress integration**

- Overview and Live dashboard widgets.
- A views column on post list screens and an analytics panel in the editor.
- Suggested privacy policy text, and personal data exporter and eraser hooks.
- WP-CLI: `drain`, `gc`, `info`, `salt`, `geo`, `privacy`, `report`, `seed`.
  Every one of them optional - the geo database installs from the Locations
  screen and maintenance runs from buttons on Settings, so a site administered
  entirely through the browser needs no terminal.
- Multisite-aware tables, settings, capabilities, cron and permissions.
- Contact Form 7, Gravity Forms and WooCommerce event integrations in Pro.

**Privacy**

- A Privacy screen stating what is stored, what is not, and the posture of the
  site, with a verified tick or warning per rule.
- Consent records and journey data in separate tables with separate retention,
  behind explicit opt-in.
- Hard retention caps enforced in the sanitizer.

### Security

- Three custom capabilities, checked on every screen, export, REST route and
  CLI command that reads data.
- Prepared statements throughout; table names are the only interpolated values
  and never come from a request.
- Comparison paths authorised against the rows on screen, not merely
  sanitized, so private content cannot be probed through a URL parameter.
- The public collection endpoint always answers 204 with an empty body, so it
  cannot be used as an oracle.

[0.1.0]: https://github.com/Coysh-Digital/wp-honest-analytics/releases/tag/v0.1.0
