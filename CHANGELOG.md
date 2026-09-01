# Changelog

All notable changes to Honest Analytics are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.0] - 2026-09-01

### Added

- **An "All time" range.** The toolbar stopped at twelve months, so a site with
  more history than that had no way to see it. All time starts at the earliest
  day anything was recorded - imported history included - and the grouping
  control offers Year on its own once the span is long enough. The start is
  looked up only when somebody actually asks for it, so the other presets cost
  nothing. A site with nothing recorded yet falls back to the ordinary thirty
  days rather than to a single day, which would switch every chart to an hourly
  axis to say the same "nothing here".
- **A permanent dismissal on the write spool warning.** It could only ever be
  snoozed for thirty days, which for somebody who has looked at the exposure and
  decided it is handled is a reminder every month for the life of the install.
  "Don't show this again" sits beside the snooze. It silences the banner and
  nothing else: Health still reports the fault to the CLI, the health filter and
  the Settings screen, so the warning stays findable by anybody who goes looking.

### Fixed

- **Changing the date range on a page detail view returned to the Pages list.**
  Every range, grouping and comparison button was built from the state that
  crosses screens, and the path being looked at is deliberately not part of
  that. It is now kept by any link that stays on the screen it was built on, and
  still dropped by one that moves - so "back to Pages" and the drilldown links
  from the Dashboard behave exactly as before. The custom range picker had the
  same hole and is fixed with it.
- **"When people visit" said "Last 7 days" whatever it had actually covered.**
  The grid cannot follow the selected range: past the hourly retention window
  the rows have been folded into daily totals and the hour genuinely is not
  recorded any more. What was wrong was the card, which printed the retention
  setting rather than the window it drew. It now names the dates it covered,
  says plainly when the selected range asked for more than is kept, and offers
  the setting that widens it. A period entirely older than the window gets its
  own answer rather than "nothing to show here yet", which read as "nobody came"
  about a period that may have been the site's busiest.

### Changed

- **"Replace or remove the database" on Locations is a button.** It opens the
  same panel in the same place; it just no longer looks like a caption.

## [0.8.5] - 2026-08-26

Everything here came out of running Plugin Check, the plugin directory's own
checker, against the packaged zip for the first time. It found four errors. Two
were real, and one of those had been hiding behind a suppression that did not
work.

### Fixed

- **A `phpcs:ignore` had been silencing nothing for as long as it has been
  there.** The geo database delete carried a suppression for `unlink()` on the
  line above the call, while the call's own line carried a different one for a
  silenced error - and a same-line directive replaces the preceding-line
  directive rather than adding to it. So the sniff had been firing, unread, and
  the comment above it had been describing a decision nobody was making. The
  call is now `wp_delete_file()`, which is the function the directory asks for
  and which lets a host filter a deletion it cares about; because that function
  returns nothing, whether the delete worked is now read off the disk instead
  of off a return value. `Loopback` already deleted files this way, so this is
  the house style rather than a new one.
- **One file in the package looked unprotected against direct access.** Every
  PHP file in this tree ends its `use` block and then guards on `ABSPATH`, and
  `Plugin.php` did too - but its `use` list is long enough that the guard sat
  at line 58, past the point the directory's checker stops looking. The file
  was as protected as every other one, and reported as protected by none of the
  tools that matter. The guard now sits above the `use` block in that one file,
  with a note saying why it breaks the convention the rest of the tree keeps.

### Changed

- **`composer.json` ships in the package.** `vendor/` has always shipped, and
  the manifest that produced it was excluded on the grounds that it is a build
  file. A package carrying a vendor directory with nothing beside it saying
  what is in there is harder to audit than it needs to be, and that is what the
  checker objected to. The lock file stays out: it describes a build that has
  already happened.
- **`THIRD-PARTY-LICENSES.md` moved to `licenses/`.** It ships either way, and
  it must - it is the attribution for everything bundled. The plugin root is
  expected to hold a specific short list of markdown files, and a package that
  wanders outside that list makes a reviewer look twice at the one thing they
  should be able to take for granted. `LICENSE.md` and `README.md` point at the
  new path.

## [0.8.4] - 2026-08-24

### Added

- **The plugin directory has an icon and a banner.** `.wordpress-org/` held a
  to-do list and nothing else, and a listing with no icon reads as abandoned
  before anybody has read a word of it. Both carry the marketing site's own
  logo - the chart from its `favicon.svg`, its palette, its serif - and are
  drawn by `bin/directory-assets.py` rather than exported by hand, so the
  directory cannot end up showing a mark the site stopped using. Screenshots are still outstanding, and are noted
  there as needing to come from a Lite install rather than from the demo,
  which runs this tree and so behaves as Pro.
- **A second workflow keeps the listing up to date without a release.** The
  directory serves `readme.txt` and the images from SVN, and neither needs a
  version bump - but the only path to SVN was the release job, so correcting a
  typo meant shipping an update to every site. `listing.yml` deploys the readme
  and the images alone when they change on `main`, and refuses to run if the
  stable tag and the plugin header disagree, since a readme in trunk naming a
  version that was never released takes the listing down to a "not found".

### Changed

- **The readme said the free edition makes no outbound request at all, and it
  does.** The measuring path does not, and never has - but the Google
  Analytics import talks to `accounts.google.com`, `oauth2.googleapis.com` and
  the Analytics APIs, and the geo installer fetches whatever HTTPS address an
  administrator types. Three categorical claims, in `readme.txt`, `README.md`
  and the `Loopback` docblock, are now the accurate narrower ones: nothing
  leaves the server while it counts, and the only third-party calls are the
  ones somebody starts. A plugin whose whole argument is that it tells you
  what it does cannot afford to be wrong about this one.
- **`readme.txt` gained an External services section**, naming every endpoint
  the two optional features contact, what is sent to each, and whose terms
  govern it - and saying plainly that the daily loopback probe is the site
  calling itself, so that a reader who greps for `wp_remote_get` has already
  been answered.
- **The readme changelog covers every released version again.** It stopped at
  0.5.0 while the stable tag said 0.8.4, so seven releases were missing from
  the only changelog most people read. Upgrade Notice was seven releases stale
  as well.
- **The release workflow can be run by hand without inventing a version.**
  `workflow_dispatch` derived the version from the ref, which on a manual run
  is a branch name, so it would have published something called `main`. It now
  asks which tag to publish and refuses anything that is not a version.
- **The short description leads with what people search for.** It is the one
  line shown under the plugin name in search results, and it said "in your
  WordPress admin" where the phrase people use is "dashboard".

### Fixed

- **The `Plugin URI` header pointed at a page that does not exist.**
  `coysh.digital/plugins/honest-analytics` returns 404, and that header is
  what the plugin directory renders as the plugin's homepage and what a
  reviewer clicks first. Both editions now point at `honest-analytics.com`,
  and `bin/build.sh` fails the build rather than silently skipping the Pro
  rewrite if that line ever stops matching - a miss there would ship Pro with
  no `Update URI`, and WordPress would offer the directory's free plugin as an
  update to a paid install.
- **A warning told the user to read a file that is not in the plugin.** The
  write-spool notice named `docs/caching.md`, which `.distignore` strips from
  the zip, so it exists on no installed site. It now gives the URL in the
  public repository.

- **The Settings screen understated the tracker by half a kilobyte.** It has
  said "a 1.3 KB first-party script" since that was true, and route tracking
  took the file to 1.9 KB without anything connecting the sentence to the file.
  The readme on wordpress.org and the handoff notes carried the same number.
  All three are corrected, and `composer budgets` now gzips the tracker and
  fails if any of them disagrees with it - a figure a visitor could check for
  themselves is not one to leave wrong.
- **Screenshots of the block editor had a modal across the middle of them.**
  The capture runs as an administrator created for the run, so WordPress showed
  it the welcome guide every time, over the analytics panel that is the only
  reason the screen is photographed. The script dismisses it.

## [0.8.3] - 2026-08-24

### Changed

- **Nine reports stopped sorting half a kilobyte per row to produce an
  eight-byte answer.** Campaigns, regions, events, outbound clicks, site
  searches, crawlers, browsers and operating systems, the referring-host
  breakdown and all three Search Console reports grouped their totals by the
  stored text of the thing being counted - a 500-character column with no index
  on it - when every one of those rows already carried the numeric id of that
  text. They now add up on the id and fetch the labels for the survivors
  afterwards. The figures are the same; a site with a lot of distinct campaign
  names or search terms gets them without a temporary table sized by its
  vocabulary.
- **The per-page trend finds its pages by id rather than by matching their
  text.** It was handed a list of paths and compared each against that same
  unindexed column for every row in the range, to identify rows by a value it
  had already been given the id for.

## [0.8.2] - 2026-08-24

### Changed

- **Finished periods are worked out once rather than on every render.** Only
  the headline figures were held; campaigns, locations, events, outbound
  clicks, searches, scroll depth, the three Search Console reports and all four
  Content reports recomputed every time somebody opened the screen - including
  for a month that ended in March, whose answer cannot change. Fourteen reports
  are now held for an hour once their period has finished. A period containing
  today is still worked out every time, on purpose: somebody refreshing to see
  whether the morning's post is doing anything should see the truth.

## [0.8.1] - 2026-08-24

### Fixed

- **Translations were four versions out of date.** The template translators
  work from was last generated at 0.1.0, so it stamped the wrong version into
  every translation downloaded from wordpress.org and was missing several
  hundred strings added since - which a translator experiences as a screen half
  in their language and half in English, with nothing to say the English half
  was ever offered to them. It is regenerated, now carries all 1,374 strings,
  and `composer pot` rebuilds it so it cannot quietly fall behind again.
- **Dates in sentences ignored the format set in Settings > General.** "Support
  runs until", "data through", "from X to Y" and the import's start and finish
  times all printed a fixed British-style date on a site that writes
  `2026-03-03` everywhere else. They follow the site's setting now. Dates in
  table cells and on chart axes deliberately do not: `l, jS F Y` is a
  legitimate choice and a column sized for `3 Mar` cannot hold "Tuesday, 3rd
  March 2026".
- **Compact numbers and percentages could not be translated.** The `k` and `M`
  on a shortened figure and the `%` sign were English, appended in code with no
  way for a translation to move, replace or space them - several locales write
  `12,3 %`.
- **Two rows of the privacy table could have silently become one.** The facts
  table, the consent counts and the import preview's headline figures were all
  arrays keyed by their own translated label, so two labels translating alike
  in some locale would collapse two rows into one - and on the privacy screen
  the row that vanished would be a claim about what the plugin does with
  people's data, on the page somebody opens to check exactly that.
- **A different copy of the charting library broke the charts instead of
  standing down.** `Chart` is a global and another plugin's version 2 or 3 wins
  or loses by enqueue order; both have the object the guard looked for and
  neither takes the options written against version 4, so the charts threw part
  way through drawing rather than showing the message that explains it.
- **A drain that threw on one site of a network wrote the rest into it.** The
  CLI drain switched to each site in turn and switched back afterwards, but not
  in a `finally`, so an exception left every query after it pointed at the site
  that failed.
- **Uninstalling a network where every site kept its data still deleted the
  licence.** One purchase covers a whole network, so on the one combination
  where nothing else was removed the licence record was the only casualty. It
  now goes only when at least one site asked for its data to go.

## [0.8.0] - 2026-08-24

### Added

- **A count of the views that were dropped, on the Settings screen.** A hit too
  large to store, a write queue at its ceiling, a batch set aside after
  repeated failures: each of those is a view that will never appear in a
  report, and until now each was written only to the debug log, which is off on
  every site that has not been asked to turn it on - which is the same set of
  sites where nobody would find out. The count is kept, the health summary
  reports it, and there is a button to clear it once whatever caused it has
  been fixed. What was in the dropped views is gone; this only says how many
  there were and when the last one happened.

### Changed

- **The drain reads the sessions it needs in one query rather than one each.**
  Working out where a batch of visits came from fetched every session
  separately and then the next step fetched the same rows again.
- **A health probe left behind by a request that was killed is cleared up.**
  The check that asks whether the write spool can be fetched over the web
  writes a file, asks for it, and deletes it - so the only way one survives is
  the site being slow enough for PHP to give up half way, which is the site
  most likely to be running the check. They were accumulating one per failed
  attempt in the directory the check exists to protect.
- **The first step of the import wizard asks the database once instead of six
  times** for the same list of previous runs.

### Fixed

- **A broken coverage table read as "nothing has been imported yet".** The
  import wizard's overlap check ran its own copy of the query that finds which
  dates a source already covers, and cast a failed read to an empty result. So
  a coverage table that had been dropped, or a query the database refused, came
  back as "no clash" - the one answer that walks the user straight past the
  question and ends with two sources counted on top of each other. The check is
  now the same code path the importer uses, a failed read is an error rather
  than an answer, and the step says the dates could not be checked and still
  asks whether to skip or replace.
- **Stopping an import left the progress bar moving underneath the message.**
  The screen read the job before cancelling it and then drew the page from that
  copy, so "the import has been stopped" appeared above a bar that was still
  advancing, and a refresh posted the cancellation again. Cancelling now
  redirects, as starting already did.
- **An import started without JavaScript waited five minutes for its first
  batch.** Starting one from the wizard nudged the scheduler; starting one from
  the plain form did not, so nothing happened until the next cron tick. Both
  nudge now.
- **A day cut short by the size of a Google Analytics report was recorded only
  in the debug log.** The importer stops after a fixed number of pages to keep
  one day from running forever, which is right, but the note saying so went to
  a channel that is off unless `WP_DEBUG` is on. It goes to the import's own log
  now, where the person who ran it can see it.
- **Google imports ignored their time budget.** Every other importer checks the
  clock between batches; the two Google ones issued a fixed number of requests
  per batch whatever the response times were, so a slow morning at Google could
  run a batch far past the limit that exists to stop a visitor's request being
  killed part way through. Each now measures how long a chunk took and asks for
  fewer days next time if it overran, growing back a day at a time once it
  stops.
- **Native rows and imported rows could be written to each other's row.** Five
  of the keys the drain uses to find the row to add to left out which source
  the figures came from, so the lookup could match an imported row for the same
  day - safe in practice only because imports write to a different hour, which
  is a coincidence of the storage layout rather than a rule anybody wrote down.
- **A repeating value the cardinality cap had refused cost a query every
  time it appeared.** The lookup remembered the answers that found something
  and forgot the ones that did not, so once a busy site hit its cap the drain
  asked the same question a few thousand times a batch and got the same no.

## [0.7.0] - 2026-08-24

### Changed

- **"How many visitors" is one row a day rather than every row in the range.**
  Each page's row carries a sketch of who read it, and answering the question
  for a whole site meant reading every one of them and merging in memory: about
  191,000 rows for a thirty-day range at the default cardinality cap, twice
  when a screen draws and four times when it is comparing against a previous
  period. That merge is now done once, when the visit is recorded. The figure
  is identical - it is the same merge of the same visitors - and every screen
  that shows it still says it is a daily estimate, because the code that makes
  a visitor recognisable is still destroyed every night. Upgrading builds the
  daily figures from the ones already stored.
- **The reports on Pages, Sources, Devices, Content and Crawlers are cached**
  for finished periods, the way the dashboard totals already were. A period
  that has ended cannot change its answer, so recomputing it on every render
  was work with a known result.
- **Content by post type, taxonomy and author reduces the traffic to one row
  per post before joining.** Inside the hourly window each post can have
  twenty-four rows per day per page, and all of them were carried into a
  four-table join to produce a few hundred rows.
- **The spool file handling moved out of the drain into its own class.**
  Claiming a batch, reading it, counting a failure and setting one aside are
  about files on disk; what a chunk means and which transaction it belongs in
  is a different job. They were in one class of nearly a thousand lines.
- **The import wizard works out a date range in one place.** It did it twice -
  once when drawing the form and once when reading it back - and the two had
  drifted apart, so "the last 12 months" could mean two slightly different
  things depending on which half you asked.

## [0.6.0] - 2026-08-24

### Added

- **Pages on client-routed themes are counted.** The tracker now notices a
  route change made with the History API - `pushState`, `replaceState` and the
  back and forward buttons - and treats it as both an ending and a beginning:
  the view being left is closed with its own reading time, and the new one
  opens. It also records which page a view belongs to when that view *starts*
  rather than when the request is sent, which on a theme that navigates without
  reloading are different answers: four articles read in a row used to arrive
  as a single view, credited to whichever address happened to be showing when
  the tab was closed. A router that navigates by the `#fragment` alone is still
  not counted, on purpose - `#comments` is a place on a page, and counting one
  would make every anchor link on every ordinary site a pageview.
- **The Settings screen says when a day's page views have stopped being
  attributed.** The dimension cap is first-N per day, so a burst of requests
  for pages that do not exist can occupy the whole allowance and leave the
  rest of that day counted but filed under "other". Nothing is lost when that
  happens and nothing was ever said about it, which made a day look
  inexplicably empty instead of explicably so.

### Changed

- **Closing a batch of visits writes hundreds of rows rather than hundreds of
  thousands of statements.** Every session that ends in the same hour belongs
  on the same row of the sessions rollup, and they were written one at a time -
  a lock, a read, a deserialise, one hash added, a re-serialise and a write,
  per session, against a single sketch. At around thirty thousand sessions a
  day that was a quarter of a million statements to produce a few hundred rows.
  Sources and devices collapse the same way. The figures are identical; there
  are far fewer of them being written.
- **The top-pages report groups on an eight-byte key rather than a
  five-hundred-byte one.** It joined the dimensions table before aggregating,
  which put the full path text into the temporary table's grouping key for
  every distinct page in the range. It now aggregates on the rollup alone and
  looks up the paths of the rows that survive.
- **Scroll depth asks the database for the rows it is going to show.** It had
  no limit in its query at all: every scrolled page in the range was fetched
  and then cut down to a handful afterwards.
- **The orphaned-dimension sweep runs weekly rather than nightly.** It asks each
  of the twenty-four columns that reference the dimensions table which of a
  thousand candidate ids it still uses, once per thousand - so a hundred
  thousand dimensions is 2,400 queries, most against columns that lead no index.
  It is the most expensive thing the tidy-up does and the least urgent: an
  unreferenced row is a few dozen bytes and harms nothing while it waits.
- **The health check asks the loopback question once per request.** It was asked
  up to six times per admin render, and once a day - when the cached verdict
  expires - the first of those writes a probe file and makes two HTTP requests
  with a five-second timeout each, while the notice area is being drawn.
- **`Schema::tableExists()` is memoised per request**, rather than running
  `SHOW TABLES LIKE` on every dashboard and import screen render.
- **The live counter and the import progress figures use the site's locale**
  rather than the browser's, so they group their thousands the same way as the
  numbers rendered beside them. The scroll-region labels the admin script
  writes for screen readers are translatable, which they were not.
- **Translations load on `init` rather than `plugins_loaded`.** Loading them
  that early resolved the locale before a multilingual plugin had had the
  chance to set it, and forced the current user to be resolved early in the
  admin.
- **Three public filter callbacks accept `mixed`.** Under universal
  `strict_types` a plugin returning null from `template_include` would have
  fatalled the whole front end inside this plugin rather than wherever the
  mistake was.
- **`Tested up to` says 7.1**, which is what CI has been running against.
- **The admin fallback no longer runs on background requests.** `admin_init`
  fires on `admin-ajax.php` too, and Heartbeat ticks every fifteen seconds
  while the block editor is open - so with a thirty-second throttle and a
  five-second budget, an active import stalled roughly every other autosave for
  up to five seconds. It also read a non-autoloaded option before its own
  throttle, which is an uncached database query on every admin request all day
  to answer a question that is interesting once.
- **Indexes for the maintenance sweep and the single-page reports.** Schema
  version 6. Every rollup index led with `siteId`, and none of the nightly
  tidy-up's predicates filter on it - they filter on a bare date, so each of
  the fifteen tables it walks was scanned whole, in five-thousand-row batches,
  every night. `honest_drainlog` had no index on the column it is pruned by at
  all. Nothing could seek on `pathDimId` either, so every single-page report
  range-scanned the site's whole date window and filtered it in memory.
  `honest_journeys` gains the three it was missing, including the one the
  Privacy screen counts distinct visitors with - on the only table here that
  grows with traffic.
- **The Privacy export asks two indexed questions instead of one unindexed
  one.** Exporting a subject's data matched `visitorId = ... OR userId = ...`
  across two separate indexes, which is a full scan of `honest_journeys` plus a
  filesort. It is a `UNION ALL` of the two indexed branches now.
- **Static analysis runs at level 8, and against the whole supported PHP
  range.** The configuration said level 6 while three merged commit messages
  said level 8, so the checks the history claimed had passed were not the
  checks that ran. Level 8 reported sixty problems; all sixty are
  fixed rather than baselined or silenced, and four of them were real - they
  are in "Fixed" above. `phpVersion` was also pinned to the 8.1 floor, so
  nothing had ever been analysed against 8.2, 8.3 or 8.4, which is the version
  CI runs the unit tests on; it is now a range covering both ends.
- **PHP compatibility is checked by something that knows about PHP after
  8.0.** `phpcs.xml.dist` has always declared `testVersion 8.1-`, but the
  installed PHPCompatibility was the 9.3.5 line, released in December 2019 -
  so a passing `composer cs` said nothing at all about the range it claimed to
  cover. On the 10.0 line the false positive that forced
  `ForbiddenThisUseContexts` off is fixed, so that exclusion is gone too.
- **A second consent rate limit, on the address alone**, matching the one the
  beacon already has. The per-visitor bucket is keyed on a hash that includes
  the user agent, so varying it produced a fresh allowance every request -
  and every call that gets through writes a consent-log row, which is
  evidence and is kept indefinitely by default.
- **The Pages screen makes one query for its post links, not two hundred.**
  Deciding whether to link a row asks `current_user_can( 'edit_post' )`, and
  an unprimed capability check is a query each.
- **The auto-drain asks whether the write queue has a row rather than
  counting them all.** On the database driver that check runs after every
  page and every beacon, and `COUNT(*)` walks the whole index to answer it.
- Removed `assets/admin/js/settings.js`, which was shipped in both builds and
  loaded by neither: nothing enqueued it, and none of the markup it enhances
  exists any more.
- **The key-value table is swept a little on every drain, in chunks**, rather
  than once a night in a single statement. On a busy site without an object
  cache that statement was a million expired nonce and rate-limit rows
  deleted at once on the one table the capture path writes to on every
  request.
- **Fewer writes per request on sites without an object cache.** The throttle
  checks that run on every front-end request and every admin page now read
  the table before they try to write to it, which turns two write statements
  into one read for all but one request a minute. The crawler check is also
  answered once per request rather than up to four times, and a batch of
  sessions going into the object cache rewrites the site's session index once
  rather than once per session.
- A gzipped geo database upload is bounded on its decompressed size as well
  as its downloaded one.

### Fixed

- **Every site but one on a network was told its licence had been removed.**
  The licence state is a network option, so an Agency licence activated once
  covers the whole network - but the key beside it was an ordinary per-site
  setting. So every other site saw a stored active licence with no key of its
  own, took the "the key has been removed" branch, and reported a licence that
  had been bought, activated and was working as missing. The key is read from
  the network licence when a site has none of its own; a key pinned in
  `wp-config.php` or set on one site still wins there.
- **A wizard step posting a nested option took the import screen down.** The
  screen mapped `sanitize_text_field` over whatever was posted, and that
  function takes a string - so an array where a string was expected was a fatal
  rather than a rejected value. The REST controller beside it has always had
  the guard.
- **Another plugin adding an inline script could stop tracking altogether.**
  The tracker's configuration was written onto the first `<script` in the value
  WordPress hands the `script_loader_tag` filter - and WordPress builds that
  value as translations, then anything registered "before", then the tag
  itself. So any plugin calling `wp_add_inline_script( 'honest-analytics', ...,
  'before' )` - consent managers and optimisers do it routinely - took
  `data-endpoint` and the hybrid nonce onto *its* tag. `tracker.js` reads its
  own tag, found no endpoint, and returned: no views counted, nothing in the
  console, no error anywhere. The configuration now goes through
  `wp_script_attributes`, which hands over the attributes of the one tag
  WordPress is building.
- **The optimiser exclusions did nothing at all on the paid edition.** The path
  they match was hardcoded as `honest-analytics/assets/js/` and the paid build
  installs as `honest-analytics-pro`, so the delay-JS features that ADR 42
  promises to hold off were not held off for the people who had paid for it,
  nor for anybody who renamed the folder.
- **On a network, whichever edition loaded first silently won.** The check for
  the other edition read only `active_plugins`, and a network-activated plugin
  is not in it - it is in `active_sitewide_plugins`, a network option. So on a
  network neither edition ever stood down, and since network-activated plugins
  load first, a network-wide free edition permanently beat a per-site Pro. The
  stand-down also deactivated per-site, which would have left a
  network-activated twin loading again on the next request.
- **Network activation and deactivation stopped at 200 sites.** Site 201 and
  everything after it was skipped, not paged. Activation recovers eventually
  through the lazy upgrade; deactivation had nothing to recover it, so those
  sites kept their scheduled events for ever - which `docs/uninstall.md`
  promises they will not.
- **Every site in a `--network` command inherited the first site's edition.**
  The edition verdict and the licence state are memoised, and resetting the
  plugin flushed only the settings - so a network where one site is licensed
  and the rest are not reported the same answer everywhere.
- **Loopback health checks failed on staging.** The self-requests set
  `sslverify` without `local`, and WordPress only applies the
  `https_local_ssl_verify` filter when a request is marked local - so a
  self-signed certificate produced a "your collection endpoint is not
  answering" warning that could not be switched off and was not true.
- **"1 year, 1 months".** The import summary chose its plural from the years
  while the months half had no singular to choose.
- **A visitor's page load could drain the whole write queue.** The five-second
  budget bound the spool files and nothing else, so on the database driver -
  the one managed hosts with a read-only filesystem use - an ordinary request
  drained the queue however large it had grown, and could be killed by
  `max_execution_time` part way through instead. The idle-session close ran
  unbounded after it. Both respect the budget now, which is what
  `docs/cron.md` has always promised.
- **One unreadable row in the write queue blocked everything behind it, for
  ever.** A failed batch released its claim and was claimed again in the same
  order on the next run, with no attempt counter anywhere - while the spool
  file beside it set the equivalent aside after three tries and carried on.
  Queue batches are counted now, and a batch that has had its three is set
  aside rather than retried. "Retry set-aside batches" and `--retry` release
  both kinds; before this they only knew about files.
- **Closing idle visits could never finish on a site using an object cache.**
  The cache store handed back every idle session on every site at once, and the
  drain wrote each one back individually - and each write rewrites the whole
  site index. Twenty thousand idle sessions against a megabyte of index is
  forty thousand full round trips in one run, which does not finish; the next
  run starts again, and the tidy-up deletes the lot after a day having counted
  none of it. A day of sources, devices, locations, campaigns, goals, entry and
  exit pages and bounce rates, silently gone. The store is capped and oldest
  first like the database one, the writes go in batches, and the close is
  chunked so it no longer holds one transaction open across every table the
  reports read.
- **Orphaned sessions were cleared at five thousand a night whatever had piled
  up.** One crash during a busy afternoon could leave two hundred thousand,
  which would have taken forty days to clear - sitting at the front of the idle
  query the whole time. The sweep loops now, like every other sweep beside it.
- **An import batch killed outright was retried for ever.** Attempts were
  counted only in the failure handler, which is reached from a caught
  exception - so a PHP fatal or an out-of-memory kill never counted one, the
  limit was unreachable, and the job was handed back on every drain while a new
  import was refused because one was already active. Cancel was the only way
  out. The attempt is counted before the work now.
- **Two things could advance the same import at once.** The scheduler took a
  lock; the REST tick and the admin fallback did not, and one open browser tab
  is enough to have two - the page re-polls a quarter of a second after the tab
  is shown without abandoning the request already running. Both counters
  double-counted and, worse, the cursor could go *backwards*, so an import
  walked back over days it had already done. Every driver takes the job's lock
  now.
- **Compaction could run a site out of memory and then stall for good.** A
  day was read whole into PHP - every hour, every path, sketches included -
  merged in memory and re-inserted a row at a time inside one transaction. At
  the default dimension cap that is around twenty-four thousand rows, and it
  scales with the cap until the process dies; the same day is then retried
  identically every night and the hourly rows never leave. Days are folded in
  pages now, each in its own transaction.
- **A lost scheduled event was never put back.** `Cron::schedule()` said in as
  many words that it ran "whenever the plugin notices an event has gone
  missing", and nothing did the noticing. Losing one is ordinary - a migration
  plugin that rebuilds `wp_options`, a staging copy, a security tool that
  clears the cron array - and three of the four jobs have a fallback that
  covers it. The Search Console daily sync has none, so losing that event
  stopped query data arriving silently and permanently. An admin page load
  repairs the schedule now.
- **Health could never report a full write queue.** The check measured the
  spool *file*, which is always empty on the database driver - so however far
  that queue backed up, nothing said so, while hits were being dropped at the
  ceiling and logged only to a channel that is off unless `WP_DEBUG` is on.
- **A resumed spool file re-read everything it had already committed.** The
  chunk markers made resuming correct, but the check came *after* decoding: up
  to twenty thousand hits were rebuilt per already-committed chunk purely to
  discover they were done, with no time check in that path. A large spool
  drained in five-second bites could spend every run on the same beginning.
  Where a run stopped is recorded now. The reader also had no line limit, so a
  spool with a missing newline was read into a single string as long as the
  file.
- **A site upgraded without anybody visiting the admin dropped hits until
  somebody did.** The schema upgrade had exactly one trigger: opening an
  analytics screen. A site updated by FTP or by a deploy therefore ran its
  drain against tables missing a column the new build writes, so every batch
  failed on `Unknown column`, was retried three times and set aside - over and
  over, while the write spool climbed to its ceiling and began dropping hits
  outright. The upgrade now runs from cron and from WP-CLI, and the drain
  stands down and holds the spool until it has, so nothing is lost by waiting.
  There is a button on Settings for a site with neither.
- **A failed migration was never retried, and could leave a table without its
  unique key.** `widenBucketKeys()` dropped a unique index and added it back
  with neither statement checked, then recorded the new schema version whatever
  had happened. Two requests arriving together both ran it, and an upsert
  landing between the drop and the add inserted duplicates instead of updating
  - after which the `ADD UNIQUE KEY` failed on "Duplicate entry" and the table
  was left with no unique key at all, permanently, because the version said the
  work was done. From there every upsert appends a row and every figure on
  every screen multiplies. The migration is now under a lock, adds the wider
  key before dropping the old one, checks every statement, and leaves the
  version alone unless all of them succeeded.
- **An import could commit a day it had emptied and never written.**
  `ImportSink` wrote its rows in chunks inside a transaction, and a chunk that
  failed was logged as a warning and skipped. A lock-wait timeout on one insert
  rolls back that statement and nothing else, so the day's deletion stood, the
  other tables committed, and `ImportRunner` recorded coverage for a day that
  was now a hole. Nothing retried it, because nothing had failed as far as any
  caller could tell. Every statement in the sink now throws, which is what the
  rollback and the three-attempt retry beside it were always for.
- **A replace that failed part way deleted history without putting any back.**
  Displacing the previous source's day happened before the transaction opened
  and recording coverage happened after it committed. A source that started
  failing during an eighteen-month replace left the earlier import's days gone
  with nothing in their place; a crash between the write and the record left
  rows no overlap check could see, which is the double count the coverage table
  exists to prevent. All of it is one transaction now.
- **Compaction destroyed anything written while it was thinking.** It selected
  a day, merged it in memory, and only then opened the transaction that deleted
  the whole day and inserted the fold. A replayed batch, a recovered spool or
  seeded history landing in that window was deleted without ever being merged -
  and those are precisely the writers that produce late rows for an old date.
  The read is inside the transaction now, and takes its locks with it.
- **Two tidy-ups could run at once.** Cron, the fallback on an admin page load,
  the button on Settings and the WP-CLI command are four ways to start one and
  none of them knew about the others, so two runs could compact the same day
  against each other. A second run now stands down, and the Settings button
  says so rather than reporting that it swept nothing.
- **A salt that could not be saved made every visitor a new visitor.** The
  rotation ignored the result of its own write and cached the new value
  regardless. With a missing or read-only salts table every request minted a
  salt of its own, so unique visitors quietly equalled page views - on every
  screen, for ever, while the Privacy screen reported the salt had never been
  created. The write is checked, and a failure keeps the salt already in the
  table rather than inventing one per request.
- **Imported Search Console history was deleted by retention.**
  `docs/import-architecture.md` says imported history outlives the window this
  site keeps its own measurements for, and that held for the six rollups with a
  `source` column. `honest_searchconsole_rollup` has no such column, so it took
  the unsourced branch of the nightly sweep and lost every row past the
  cutoff - including history somebody had just waited for an import to fetch.
  It is exempt by name now. Its writer also cleared nothing and reported
  success whatever the database said; it now replaces a page's rows for the day
  inside a transaction, and throws when it cannot.
- **Only the last day's folded unique counters were ever discarded.**
  Compaction collected them into a field it overwrote on each day rather than
  merged, and the discard runs once at the end of a run covering four tables by
  up to two hundred days. On a site using the exact unique counter the hourly
  member rows for every other day were kept for ever, and nothing else sweeps
  them.
- **The same summary email could be sent twice.** Whether one was due was read
  from an option written only after the mail had gone, so two runs a few
  hundred milliseconds apart - cron firing twice, or cron and the admin
  fallback together - both passed the check and both sent. The period is
  claimed atomically now, and released again if the send fails, so a summary
  that could not go out is still retried. Traffic alerts had the same shape
  with a narrower window.
- **Declaring an Independent Analytics install to store UTC did nothing.** The
  day boundaries were parsed in the storage timezone and formatted in the
  storage timezone, so the conversion cancelled and the documented filter
  changed nothing but a log line. An install storing UTC behind a Sydney site
  filed every evening hit under the previous day with no remedy. The bounds are
  now built from the site's calendar day and converted into the storage clock,
  which is what the filter was always described as doing.
- **A report table whose column had been renamed took the screen down with
  it.** Thirty places sized their bars with `max( array_column( $rows, 'views'
  ) )`, guarded on `$rows` being non-empty - which is the wrong thing to ask.
  `array_column()` answers with an empty array whenever the column is missing
  from every row, and `max()` raises a ValueError on that, so a query result
  that had lost a key rendered as a fatal rather than as a table with flat
  bars. `Format::largest()` now checks the values it is about to measure
  instead of the rows they came from.
- **A date could disappear from a report with nothing to say why.**
  `wp_date()` is documented as returning false, which it does when the site's
  timezone cannot be constructed - a corrupt `timezone_string`, or a PHP build
  whose tzdata does not carry the named zone. Ten call sites handed that
  straight to `esc_html()`, where false prints as an empty cell.
  `Timezone::format()` falls back to UTC, which is at worst a few hours out
  and is a better answer than a blank one.
- **Deleting the plugin could stop half way through and leave the tables
  behind.** With "Keep data on uninstall" switched off, the spool sweep ran
  `foreach ( (array) glob( ... ) )`. `glob()` returns false on a directory it
  cannot read and on a PHP build without `GLOB_BRACE`, and casting false to an
  array gives `[ false ]` - a TypeError the moment it reached `is_file()`. The
  uninstall died there, after revoking the capabilities that would have let
  somebody open the screen explaining what had happened.
- **A stale or foreign transient could stop the Google connection screens
  drawing.** The GA4 property list and the Search Console site list were read
  back from a transient and returned as-is on the strength of `is_array()`
  alone. A transient outlives a plugin update and anything on the site can
  write one, so an entry in an older format reached the template as rows it
  had no keys for, with no way to clear it but waiting fifteen minutes. Both
  are now checked against the shape they are meant to have.
- **Every date on every screen was a day early west of Greenwich.** Stored
  dates are local calendar dates and are printed with `wp_date()`, which
  renders in the site's timezone - but the bridge between the two was
  `strtotime()`, and WordPress fixes PHP's default zone to UTC. At any
  negative offset the two disagreed by a day, so chart axes, tooltips, the
  range note on every screen and every import date read one day early, all
  year. `Timezone::middayOn()` now does the conversion, anchored at midday so
  no offset and no daylight-saving transition can carry it into a
  neighbouring day.
- **The orphaned-dimension sweep could delete dimensions that were in use.**
  It asked each rollup table which of a thousand candidate ids it still
  referenced, and `get_col()` answers a *failed* query with the same empty
  array it uses for "none of them". Losing that query - contending with a
  running drain is the ordinary way - deleted a thousand live dimension rows,
  which are the labels every rollup row is read through. The reports would
  have gone blank in patches, permanently, with no way to recover the names.
  The sweep now stops on an unreadable answer rather than treating it as
  permission.
- **Two more writes inside the drain transaction were unchecked**, and were
  missed when the rest were converted: the session upsert and the dimension
  insert. A failed dimension insert was worse than a lost batch - it resolved
  to id `0`, which is a valid-looking key, so a batch of views would have been
  filed against a row that names nothing and stayed there.
- **The real-time figure on the Dashboard stopped counting at 200.** It came
  from a call that fetches and decodes two hundred session records to build a
  visit list the Dashboard does not show, and reported how many rows it had
  fetched. It is now one indexed `COUNT(*)` with no ceiling.
- **A drain batch whose write failed was recorded as committed.** `$wpdb`
  answers a failed statement with `false` and carries on, and nothing inside
  the drain transaction looked. A deadlock - which InnoDB resolves by rolling
  the whole transaction back by itself - left the batch marker committed, the
  spool file deleted, and nothing counted. Every write under a transaction now
  goes through `Support\Db`, which throws, so the batch rolls back and is
  retried. Compaction had the same hole and the same fix: a failed insert
  after the day's rows were deleted would have committed a day with rows
  missing.
- **A session staged for closing by a batch that never committed was never
  closed.** It sat at the front of the idle queue for ever, and because that
  queue is bounded, enough of them would have stopped every later session
  from closing at all - with every session-scoped figure on every screen
  frozen as a result. Such a session is now simply closed again.
- **Compaction zeroed the imported visitor count.** When native hourly rows
  shared a date with an imported day, the fold rebuilt the imported row
  without `importedUniques`, so a migrated month lost its visitors on the day
  it left the hourly window.
- **Session deltas are applied inside the drain transaction**, as ADR 30 has
  always said they were. They were applied just before it, so a rolled-back
  batch left its sessions advanced past views that were never counted.
- **Uninstall with "keep data" off now removes everything.** It missed the
  Google OAuth client and tokens an import stores, the dismissed-notice and
  last-run options, the Search Console sync event and the PDF font cache.
  Options are now found by prefix rather than by list.

### Security

- **The geo database sat at a guessable, downloadable address.** It was written
  to `uploads/honest-analytics/geo.mmdb`. The directory is guarded with
  `.htaccess` and `web.config`, both of which nginx ignores and neither of which
  can be written from PHP for nginx - which is exactly why the write spool's
  filename already carried an install-specific code. This one did not, so
  `GET /wp-content/uploads/honest-analytics/geo.mmdb` handed anybody who tried
  it a seventy-megabyte MaxMind or DB-IP build: a redistribution of a database
  the site is licensed only to use, and a large hole to pour bandwidth through.
  The health check probes the spool directory only, so nothing warned. The
  filename carries the same code now, and a database already installed is
  renamed rather than downloaded again.
- **A directory that lost its guard files never got them back.** They were
  written only at the moment a directory was created, so an rsync that skipped
  dotfiles, a restore from a backup that did not keep them, or a security plugin
  that tidied them away left the write spool readable with nothing to say so.
  They are re-asserted whenever the plugin asks for one of its directories.
- **The Google client secret is encrypted at rest.** It sat in `wp_options` in
  clear beside the two refresh tokens derived from it, which have been encrypted
  since the last release - and it is the longer-lived credential of the three.
  Values written before this change are read back unchanged and encrypted the
  next time they are saved, so there is nothing to migrate.
- **The connection broker address is pinned to https.** It is filterable and
  empty by default, so nothing uses it yet - but it carries the licence key and
  a Google refresh token, and a filter returning an `http://` address would have
  sent both in clear. The update-package check has had that floor since it was
  written; this had no reason to be softer.
- **The update check pins `download_link` as well as `package`.** WordPress
  reads that third field for the details modal's install button, and it was
  outside a host restriction the other two were inside. Core routes the upgrade
  through the pinned field in practice, so this was a hole in a defence rather
  than a way through it - but a defence with a hole in it is worth less than it
  looks.
- **The GA4 property is validated rather than sanitised.** It becomes part of a
  URL path, and `import/start` accepted it over REST with only
  `sanitize_text_field` applied - which leaves `/`, `.`, `?`, `#` and `..`
  intact. A user with permission to manage analytics but not the site could
  point the stored bearer token at an API path the wizard never offers. The
  host is fixed, so this was path manipulation rather than a request to
  somewhere else; it was still not theirs to choose.
- **The user agent and the referrer are reduced in the request that saw them,
  not at the drain.** Both used to travel whole into the write spool - a file
  in `wp-content/uploads/` - and only became a browser family and a host when
  the aggregation ran minutes later. Nothing was ever aggregated from the rest,
  and nothing appeared in a report, but a full user-agent string and a full
  referrer including its query string were on disk in the meantime. On a site
  whose cron never fires, indefinitely. A hit now carries four device families
  and a bare origin, so there is nothing left to leak. `docs/privacy.md` said
  "`?token=…` never reaches disk"; it does not now.
- **The Cloudflare address source is verified rather than trusted.** Choosing
  it in the settings believed `CF-Connecting-IP` from whoever sent it. Behind
  Cloudflare that is safe, because Cloudflare overwrites the header at its
  edge - and an origin still reachable directly could be handed any address
  anybody liked, which is an unlimited supply of visitor identities and a rate
  limit with a fresh bucket every request. It now uses the same published-range
  check the automatic setting always did.
- **An update is only installed from the licence server.** WordPress downloads
  and installs whatever the update response names under `package`, which makes
  it the one answer in this product that ends in code running on a customer's
  server, and it was accepted as sent. It is now pinned to https on the host
  the request went to; anything else is ignored and logged.
- **Google refresh tokens are encrypted at rest.** One of those is read access
  to somebody's GA4 property or Search Console until it is revoked, and it sat
  in `wp_options` in plain text - reachable from any read-only database
  exposure or any backup left in the webroot. They are encrypted with a key
  derived from the site's own salts. Rotating those salts now means
  reconnecting Google, which `docs/importing.md` says.
- **The geo database download refuses private addresses and has a size
  ceiling.** It used `wp_remote_get`, which will reach anything; it now uses
  `wp_safe_remote_get` with a response limit, so an administrator cannot be
  talked into using that screen to reach something inside their own network,
  and a URL that answers with half a gigabyte is an error message rather than
  a white screen.
- **A second beacon rate limit, on the address alone.** The existing one is
  keyed on a hash of the address *and* the user agent, so varying the user
  agent produced a fresh allowance every request. The new bucket cannot be
  varied and is deliberately generous, because a shared office address is a
  great many real people.

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
