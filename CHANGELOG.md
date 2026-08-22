# Changelog

All notable changes to Honest Analytics are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-08-22

Client-shareable reports can now be downloaded as a PDF, with a choice of
what goes into them and a real calendar month as well as a rolling window.

### Added

- **Pro only: client-shareable reports are downloadable as a PDF.** A
  Download PDF button on the Shared reports screen for an administrator, and
  the same button on the report itself for whoever holds the link - both
  render from the exact same figures as the page, so the two can never
  disagree.
- **Pro only: a share link's content is now a choice, not a fixed set.**
  Trend, Top pages, Where visitors came from, Devices and Content can each be
  switched on or off per link - the KPI totals always show - and the choice
  applies identically to the live page and the PDF.
- **Pro only: This month and Last month join the rolling windows a share
  link can show.** Last month is the whole of the calendar month before,
  useful for a report that is meant to say "August 2026" rather than
  whichever thirty days happen to end today.
- **Pro only: a client-shareable report can carry a name of the agency's
  own choosing**, in place of the site's own name - set once on Settings,
  it applies to the live page and the PDF alike.

## [0.4.0] - 2026-08-22

Groundwork for a hosted Google connection, not switched on for anybody yet.

### Changed

- **Search Console now has the same hosted-broker groundwork Google
  Analytics already had.** A `Gsc\BrokerProvider` mirrors the existing GA4
  one exactly, and `Gsc\Connection` prefers it the moment
  `honest_analytics_gsc_broker_url` is filtered to a real address, the same
  way GA4's already does. Neither filter is set anywhere, so every site
  keeps connecting with its own Google Cloud client exactly as before.
  Rolling a hosted broker out to real installs is a separate decision, not
  part of this release.

## [0.3.0] - 2026-08-22

Four Pro reports: a link to hand a client with no account of their own, an
email when traffic breaks its own pattern, tracking for anything a click can
land on, and what people searched to find a page.

### Added

- **Pro only: client-shareable reports.** A link an administrator creates
  from the new Shared reports screen, showing a rolling window of the
  overview to whoever holds it - no WordPress account, no settings, no other
  report, and no way back into wp-admin. Individually revocable, with an
  optional expiry, rate limited, and marked noindex so a leaked link cannot
  end up in a search index.
- **Pro only: traffic spike and drop alerts.** Off by default. Once a day,
  yesterday's session total is compared against the typical figure for that
  weekday, drawn from the site's own last four occurrences of it, and an
  email states the real numbers rather than a bare percentage. A drop to
  zero - tracking having quietly broken - is the case this is built for.
- **Pro only: click tracking on any element.** A `data-honest-event`
  attribute an author can add in the block editor, or a short list of CSS
  selectors configured on Settings, each recording a named event with
  nothing else about the element it was attached to.
- **Pro only: Search Console query data.** A separate, narrowly scoped
  Google connection imports what people searched to reach each page -
  queries, clicks, impressions and average position - onto a new Search
  Console screen and as a panel on every page's detail view, always shown
  apart from this plugin's own figures rather than added to them.

### Fixed

- **The "See what Pro adds" link on a locked report went to the wrong
  domain.** It named the unhyphenated spelling of the site, which belongs to
  somebody else and has been parked since 2015, so anybody clicking through
  from a locked report landed on a parking page rather than the pricing page.
  It ships in the free edition, so this was every Lite site.

## [0.2.3] - 2026-08-22

Comparison finishes reaching the screens it was missing from, and Locations
gets a map.

### Added

- **Comparison now shows on Devices and Sources.** Both screens gained the
  same site-wide totals row Dashboard and Pages already show, with a
  percentage change against whichever period is being compared.
- **Ranked tables show a per-row change, not just the totals row.** The
  Pages, Devices and Sources tables each show a change beside their headline
  column - Views, Sessions - when a comparison is active, matched to its
  counterpart by identity rather than by rank, since the top row this period
  is not guaranteed to be the top row, or present at all, in the one being
  compared against.
- **Locations shows the country, not just its code**, a breakdown by region,
  and a shaded world map built from the same figures as the table beside it.
  Region is derived the same way it already was internally; nothing new is
  collected, and city-level location is still never recorded.
- **Pro only:** the Licence screen links to the account area at
  pro.honest-analytics.com, to manage seats or view billing without leaving
  wp-admin to look it up.

## [0.2.2] - 2026-08-22

**Pro only.** The paid edition now talks to a real licence server instead of
taking a well-formed key's word for it.

### Added

- **Activating, deactivating and checking a licence now calls
  `pro.honest-analytics.com`.** Entering a key claims a real seat, an
  over-used or refunded key is reported as such, and a seat released from the
  account area is picked up here. As before, a network problem never takes
  Pro away from a site that already had it - only a clear answer from the
  server changes what is stored.
- **Updates for the Pro build now come from the same server**, offered
  through the normal WordPress update screen once a key is active.

### Fixed

- **The "View version details" link for the Pro build never opened.** It
  checked the free edition's slug regardless of which one was actually
  installed, so the modal silently failed to answer for every Pro site. It
  now checks the slug this build actually installed as.

## [0.2.1] - 2026-08-22

Two problems with the comparison feature added in 0.2.0.

### Fixed

- **"No comparison" still showed a comparison.** Every headline figure on
  Dashboard and on a page's own report kept its percentage change against
  the previous period even with the toggle set to no comparison at all. Every
  card is now silent about change when no comparison is active, not just the
  ones whose note named a period.
- **The comparison line's tooltip and legend named only the period being
  compared - "Same period last year" - which read as a fourth metric sitting
  next to Pageviews and Unique visitors rather than as Pageviews measured at
  a different time.** It now says which: "Pageviews vs same period last
  year".

## [0.2.0] - 2026-08-22

Reports can now be read at a glance across years, not just days, and figures
mean something once there is something to measure them against.

### Added

- **A date range spanning more than one year now says so on the chart.** Axis
  labels carry the year whenever a point could otherwise be mistaken for the
  same month a year apart.
- **Trend charts can be grouped by day, week, month or year**, independently
  of the date range chosen - a three-year range read by month, for instance.
  The control only offers grains a range is actually long enough for.
- **Compare a period against the one before it, or the same period last
  year.** The trend chart draws the comparison as a second, dashed line, and
  every headline figure on the Dashboard and on a page's own report shows the
  percentage change alongside it. The Pages screen gained the same headline
  row it was missing.
- **The date range, grouping and comparison chosen on one screen now follow
  you to the next.** A link that names them explicitly still wins, so nothing
  bookmarked or sent to somebody else changes; a plain click through the
  sidebar picks up whatever was chosen last instead of resetting to thirty
  days.
- **The write-spool warning can now be dismissed** for thirty days at a time,
  rather than every time until the underlying nginx configuration is fixed.
  It comes back on schedule only if the exposure is still real.

### Changed

- **Rollup retention now defaults to thirty-six months**, up from
  twenty-six - long enough to compare a month against the same month a year
  earlier with room to spare.

### Fixed

- **History brought in from Google Analytics, WP Statistics or Independent
  Analytics could be quietly deleted the night after import**, the moment it
  fell outside the retention window - the opposite of what the import wizard
  told you would happen. Imported history is now kept regardless of the
  retention setting, and the wizard says so before you start.
- **A day that mixed native traffic with already-imported history could be
  merged into one row by the nightly tidy-up**, once that day fell inside the
  compaction window, silently losing which source the figures belonged to.
  Native and imported rows are now kept apart through compaction the same way
  they are kept apart everywhere else.
- **The range step could claim a property had one day of analytics when it
  had years.** It read only the cached date range, which is empty the first
  time anybody reaches the step after choosing a property, and fell back to
  today on each side independently. The range is now discovered properly
  before the step is drawn.

## [0.1.2] - 2026-08-21

A GA4 connection said the same thing whether the redirect address was wrong,
the Client ID and secret did not match, or an API was not switched on yet.
Now it says which.

### Added

- **Step 3 of the Google setup guide links straight to enabling each API**,
  rather than naming them for a console search.
- **The Client ID field flags an obviously wrong paste** before it round-trips
  through a Google error - non-blocking, since the format is common rather
  than guaranteed.

### Fixed

- **A failed Google connection said one generic sentence for every cause.** A
  mismatched redirect address, an unrecognised Client ID or secret, and a
  Google Analytics API that is not switched on yet now say which of those it
  was, instead of guessing at two of them in one sentence.
- **A wrong Client ID or secret looked like an expired connection.** Google
  answers both with the same HTTP status, and the connection told people to
  reconnect - which fails again, identically, with the same wrong secret. It
  now says the sign-in details do not match instead.

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
