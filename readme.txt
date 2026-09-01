=== Honest Analytics ===
Contributors: coyshdigital
Tags: analytics, privacy, statistics, cookieless
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless website analytics in your WordPress dashboard. No third-party service, no IP addresses stored, no per-visitor rows.

== Description ==

Honest Analytics counts your traffic and shows it in the WordPress admin. Nothing is sent anywhere, and nothing is stored that could identify a visitor later.

= What it never stores =

* **No IP addresses.** Not in a table, not in a log, not in a cache key, not in the write spool. The address exists as a variable inside one function, is hashed, and is gone.
* **No full referrer URLs.** Only the host. `?token=…` never reaches disk.
* **No full user-agent strings.** Parsed into browser, operating system and device type, then discarded.
* **No raw pageview records.** Reports are built from aggregate rollups.
* **No cookies** in the default configuration.
* **No third parties in the counting.** No telemetry, no CDN, no fonts, no map tiles, no licence check, no update check. Measuring a visit never leaves your server. The only requests that go anywhere else are the ones you start yourself - importing your history from Google Analytics, or fetching a geo database from an address you type. Both are described under External services below.

= How it counts people without identifying them =

A visitor is a short hash of a random daily salt, the address, the user agent and the site. The salt is overwritten in place every 24 hours, so yesterday's hashes cannot be recomputed by anybody - including somebody holding your database.

Uniqueness is estimated from a fixed-size sketch stored on the rollup row, accurate to about ±1.6%. It holds no identifiers and cannot be asked whether it contains a particular person.

This is why **unique visitors are daily estimates**, not people, and why the plugin says so on every screen that shows the number. The same person visiting on three days counts three times. There is no honest way to produce a cross-day or lifetime unique-visitor figure from this model, so the plugin does not offer one.

= It works behind a page cache =

The server counts the requests it sees. A 1.9 KB first-party script confirms the rest from the browser. A nonce reconciles the two - consumed once per *visitor*, not once per nonce - so one piece of cached HTML served to a thousand people counts a thousand times.

No cache exclusions. No hole punching. No cache-plugin add-on. Tested with WP Rocket, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler, SG Optimizer, NitroPack and Cloudflare.

= It respects opt-out signals =

A request carrying `Sec-GPC: 1` is not counted, not queued, and is sent no tracker at all. On by default. Do Not Track is supported the same way, off by default.

= Storage that does not grow with your traffic =

Storage grows with dimensions × time, not pageviews × time. A site with a hundred thousand views a day uses roughly the same disk as one with a hundred. Hourly detail is kept for a week, then compacted to daily; everything is deleted at 36 months at the latest.

= What is in this free edition =

* Dashboard, with pageviews, daily unique visitors, sessions and bounce rate
* Real-time visitors
* Pages, and a detail view for any one page
* Content, by post type, taxonomy and author
* Sources, and referring hosts
* Devices, browsers and operating systems
* Privacy, which states exactly what this site stores and what it does not
* CSV and JSON export from every report
* Two dashboard widgets, a Views column on your post lists, and an analytics panel in the editor
* WP-CLI commands, and multisite support

There are no artificial limits. No row caps, no retention caps, no date-range caps, no nag screens and no countdowns. The free edition is a product, not a trial.

= What the paid edition adds =

Campaigns, locations, events, goals, funnels, crawler reporting, and integrations with Contact Form 7, Gravity Forms, WooCommerce, WPForms and Ninja Forms. Details are at the plugin's homepage. It is a one-off payment with no subscription, and it reads the same tables this edition writes - upgrading moves no data and loses no history.

= On compliance =

This plugin is **cookieless by default** and is **designed not to require an analytics consent banner in its default configuration**. Whether that is true of your site depends on your site, your jurisdiction and what else you run, and this plugin cannot tell you that - nobody's plugin can. What it can do is tell you exactly what it stores, which the Privacy screen does, in plain words, so that the person advising you has something factual to work from.

== Installation ==

1. Upload the plugin and activate it.
2. Visit **Analytics** in the admin menu.
3. If your site is behind a full-page cache, read [Scheduling](https://github.com/Coysh-Digital/wp-honest-analytics/blob/main/docs/cron.md) - WP-Cron barely runs on a cached site, and aggregation needs a real schedule.

Requires WordPress 6.4+, PHP 8.1+, MySQL 5.7+ or MariaDB 10.4+.

== External services ==

This plugin makes no outbound request in the course of measuring your traffic. Nothing about your site or your visitors is sent to us or to anybody else, and there is no telemetry, licence check or update check in the free edition.

Two features do contact somewhere else. Both are optional, both are started by an administrator, and neither runs unless you use it.

= Google Analytics, when you import your history =

If you choose to bring your history over from Google Analytics, the plugin talks to Google on your behalf, using a Google Cloud client that belongs to you and credentials you enter yourself. Nothing happens until you connect an account on the Import screen, and disconnecting revokes the token.

What it contacts:

* `accounts.google.com` - to send you to Google's own sign-in screen so you can grant access.
* `oauth2.googleapis.com` - to exchange that grant for an access token, and to revoke it when you disconnect.
* `analyticsadmin.googleapis.com` - to list the Analytics properties your account can see, so you can pick one.
* `analyticsdata.googleapis.com` - to read the historical figures for the property and date range you choose.

What is sent: your own OAuth credentials, the property identifier you picked, and the date range and metrics being requested. No data about your WordPress site, its visitors or its content is sent. The access is read-only - the plugin asks for the `analytics.readonly` scope and nothing more.

This is Google's service, governed by Google's terms and privacy policy, not ours: [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy). The API is documented at [Google Analytics Data API](https://developers.google.com/analytics/devguides/reporting/data/v1).

= A geo database, if you install one =

Country and region reporting needs a MaxMind-format database, which is not bundled with the plugin. You can upload a file, or give the plugin an HTTPS address to fetch it from.

No address is built into the plugin and nothing downloads on its own. The request goes only to the URL you type, sends nothing but the request for that file, and is refused unless it is HTTPS and the response is a valid database. Common sources are [MaxMind GeoLite2](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data) and [DB-IP Lite](https://db-ip.com/db/lite.php), each under its own terms; whichever you choose, the terms are between you and them.

= What is not a third party =

Once a day the plugin asks *your own site* two questions: whether the collection endpoint still answers, and whether the write spool is readable over the web. Those are HTTP requests, so they show up in a search for `wp_remote_get`, but they go to your own address and nowhere else. They exist because neither fact can be settled any other way - a security plugin can disable the REST API without saying so, and the rule written at activation does nothing on nginx.

== Frequently Asked Questions ==

= Do I need a cookie banner? =

In the default configuration no cookie is set, no identifier persists past the day, nothing is stored per person and no third party is involved. Many operators treat that as outside consent requirements. Whether it is outside *yours* is a question for whoever advises you on it - this plugin will not tell you that you are compliant, because it is not in a position to know.

The Privacy screen states exactly what the configuration permits, in as many words, so that conversation can start from facts.

= Why are my numbers different from Google Analytics? =

Usually higher, because content blockers do not stop server-side counting. Where they are lower it is normally GPC: visitors who ask not to be counted are not counted, at all.

= Why do unique visitors not add up across days? =

Because the identity is destroyed every 24 hours. Unique visitors are daily estimates, and adding two days together would double-count anybody who came on both. The plugin merges the underlying sketches rather than summing them, and says so wherever the number appears.

= Does it work without JavaScript? =

Yes, in server mode. In the default hybrid mode a visitor without JavaScript is still counted by the server, as long as the page was not served from cache.

= Can I export my data? =

Yes. CSV and JSON from every report screen, and through WP-CLI, in the free edition. Exports are protected against spreadsheet formula injection.

= Do I need cron, or a terminal? =

Neither. Counting works on hosting with no scheduled tasks at all: ordinary page views drive the drain, the nightly tidy-up runs from the first admin page load of the day, and the identity salt rotates lazily when it falls due. Maintenance also has buttons on the Settings screen, so nothing the plugin needs doing requires WP-CLI. If you do have cron, it makes the figures slightly fresher on a busy site, and that is all it changes.

= Does it support multisite? =

Yes. Each site gets its own tables, settings and reports.

= Does it phone home? =

No. Nothing about your site, your traffic or your use of the plugin is ever sent anywhere, and there is nothing in it that reports back - no telemetry, no licence check, no update check, no remote configuration.

The plugin does make outbound requests in two situations, both of which you start and neither of which sends us anything: importing your history from Google Analytics, and downloading a geo database from an address you supply. It also calls *your own site* once a day to check that the collection endpoint answers and that the write spool is not readable over the web. External services below says exactly what each one is.

= What happens to my data if I upgrade to the paid edition? =

Nothing. Both editions read and write the same tables, so your history appears unchanged. Downgrading is equally safe: the tables are left alone and the paid reports simply stop being rendered.

= What happens if I delete the plugin? =

By default the tables are kept, because the rollups cannot be rebuilt from anything else - there is no raw hit data to replay. Deleting them is an option on the Settings screen that you have to choose deliberately.

== Screenshots ==

1. Dashboard - traffic, channels, devices, and when people visit
2. Real-time - active sessions, updating every fifteen seconds
3. Pages - ranked, comparable, exportable
4. Page detail - how visitors reached one page
5. Sources - channel mix over time
6. Privacy - what is stored, what is not, and the posture of this site
7. Settings - every default is the privacy-preserving option

== Changelog ==

= 0.9.0 =
* Added: An "All time" range on every report screen and on the dashboard widget. It starts at the earliest day anything was recorded, imported history included, and the chart grouping offers Year once the span is long enough.
* Added: The write spool warning can now be put away for good as well as snoozed for thirty days. Dismissing it silences the notice only; the Settings screen, the site health check and WP-CLI all still report the fault.
* Fixed: Changing the date range while reading one page's report returned you to the Pages list. Every range, grouping and comparison control now keeps the page you are looking at, and so does the custom date picker.
* Fixed: The "When people visit" card said "Last 7 days" whatever it had actually covered. It now names the dates it drew, says when the selected range asked for more hourly detail than is kept, and links to the setting that widens it.
* Fixed: A period older than the hourly window said "Nothing to show here yet", which read as though nobody had visited. It now explains that the hour of each visit is no longer kept for that period, and that the pageviews themselves are still counted.
* Changed: "Replace or remove the database" on the Locations screen is a button rather than a line of text.

= 0.8.5 =
* Fixed: Removing the geo database used a function the plugin guidelines discourage, behind a suppression comment that had never applied. It now uses the WordPress function for deleting a file, so a host that filters deletions sees this one.
* Fixed: One file looked as though it was missing its guard against being loaded directly. It was not missing - it sat below a long list of imports, past the point the directory's own checker stops looking.
* Changed: The package now carries composer.json beside the vendor directory it produced, so what is bundled can be read without unpacking anything.
* Changed: The third-party licence list moved to licenses/, out of the plugin root.

= 0.8.4 =
* Changed: The readme said the free edition makes no outbound request at all, and it does - the optional Google Analytics import and the geo installer both reach a third party. The claims now say what is true: nothing leaves your server while it counts.
* Changed: A new External services section names every endpoint those two optional features contact, what is sent, and whose terms govern it.
* Changed: The changelog and the upgrade notices cover every released version again, rather than stopping at 0.5.0.
* Fixed: The Plugin URI header pointed at a page that does not exist.
* Fixed: A warning told the user to read a file that is not shipped inside the plugin. It now links to it.
* Fixed: The Settings screen understated the tracker by half a kilobyte.
* Fixed: Screenshots of the block editor had a modal across the middle of them.

= 0.8.3 =
* Changed: Nine reports stopped sorting half a kilobyte per row to produce an eight-byte answer.
* Changed: The per-page trend finds its pages by id rather than by matching their text.

= 0.8.2 =
* Changed: Finished periods are worked out once rather than on every render.

= 0.8.1 =
* Fixed: Translations were four versions out of date.
* Fixed: Dates in sentences ignored the format set in Settings > General.
* Fixed: Compact numbers and percentages could not be translated.
* Fixed: Two rows of the privacy table could have silently become one.
* Fixed: A different copy of the charting library broke the charts instead of standing down.
* Fixed: A drain that threw on one site of a network wrote the rest into it.
* Fixed: Pro only: Uninstalling a network where every site kept its data still deleted the licence.

= 0.8.0 =
* Added: A count of the views that were dropped, on the Settings screen.
* Changed: The drain reads the sessions it needs in one query rather than one each.
* Changed: A health probe left behind by a request that was killed is cleared up.
* Changed: The first step of the import wizard asks the database once instead of six times.
* Fixed: A broken coverage table read as "nothing has been imported yet".
* Fixed: Stopping an import left the progress bar moving underneath the message.
* Fixed: An import started without JavaScript waited five minutes for its first batch.
* Fixed: A day cut short by the size of a Google Analytics report was recorded only in the debug log.
* Fixed: Google imports ignored their time budget.
* Fixed: Native rows and imported rows could be written to each other's row.
* Fixed: A repeating value the cardinality cap had refused cost a query every time it appeared.

= 0.7.0 =
* Changed: "How many visitors" is one row a day rather than every row in the range.
* Changed: The reports on Pages, Sources, Devices, Content and Crawlers are cached.
* Changed: Content by post type, taxonomy and author reduces the traffic to one row per post before joining.
* Changed: The spool file handling moved out of the drain into its own class.
* Changed: The import wizard works out a date range in one place.

= 0.6.0 =
* Added: Pages on client-routed themes are counted.
* Added: The Settings screen says when a day's page views have stopped being attributed.
* Changed: Closing a batch of visits writes hundreds of rows rather than hundreds of thousands of statements.
* Changed: The top-pages report groups on an eight-byte key rather than a five-hundred-byte one.
* Changed: Scroll depth asks the database for the rows it is going to show.
* Changed: The orphaned-dimension sweep runs weekly rather than nightly.
* Changed: The health check asks the loopback question once per request.
* Changed: Schema::tableExists() is memoised per request.
* Changed: The live counter and the import progress figures use the site's locale.
* Changed: Translations load on init rather than plugins_loaded.
* Changed: Three public filter callbacks accept mixed.
* Changed: Tested up to says 7.1.
* Changed: The admin fallback no longer runs on background requests.
* Changed: Indexes for the maintenance sweep and the single-page reports.
* Changed: The Privacy export asks two indexed questions instead of one unindexed one.
* Changed: Static analysis runs at level 8, and against the whole supported PHP range.
* Changed: PHP compatibility is checked by something that knows about PHP after 8.0.
* Changed: A second consent rate limit, on the address alone.
* Changed: The Pages screen makes one query for its post links, not two hundred.
* Changed: The auto-drain asks whether the write queue has a row rather than counting them all.
* Changed: Removed assets/admin/js/settings.js, which was shipped in both builds and loaded by neither: nothing enqueued it, and none of the markup it enhances exists any more.
* Changed: The key-value table is swept a little on every drain, in chunks.
* Changed: Fewer writes per request on sites without an object cache.
* Changed: A gzipped geo database upload is bounded on its decompressed size as well as its downloaded one.
* Fixed: Pro only: Every site but one on a network was told its licence had been removed.
* Fixed: A wizard step posting a nested option took the import screen down.
* Fixed: Another plugin adding an inline script could stop tracking altogether.
* Fixed: Pro only: The optimiser exclusions did nothing at all on the paid edition.
* Fixed: On a network, whichever edition loaded first silently won.
* Fixed: Network activation and deactivation stopped at 200 sites.
* Fixed: Every site in a --network command inherited the first site's edition.
* Fixed: Loopback health checks failed on staging.
* Fixed: "1 year, 1 months".
* Fixed: A visitor's page load could drain the whole write queue.
* Fixed: One unreadable row in the write queue blocked everything behind it, for ever.
* Fixed: Closing idle visits could never finish on a site using an object cache.
* Fixed: Orphaned sessions were cleared at five thousand a night whatever had piled up.
* Fixed: An import batch killed outright was retried for ever.
* Fixed: Two things could advance the same import at once.
* Fixed: Compaction could run a site out of memory and then stall for good.
* Fixed: A lost scheduled event was never put back.
* Fixed: Health could never report a full write queue.
* Fixed: A resumed spool file re-read everything it had already committed.
* Fixed: A site upgraded without anybody visiting the admin dropped hits until somebody did.
* Fixed: A failed migration was never retried, and could leave a table without its unique key.
* Fixed: An import could commit a day it had emptied and never written.
* Fixed: A replace that failed part way deleted history without putting any back.
* Fixed: Compaction destroyed anything written while it was thinking.
* Fixed: Two tidy-ups could run at once.
* Fixed: A salt that could not be saved made every visitor a new visitor.
* Fixed: Pro only: Imported Search Console history was deleted by retention.
* Fixed: Only the last day's folded unique counters were ever discarded.
* Fixed: Pro only: The same summary email could be sent twice.
* Fixed: Declaring an Independent Analytics install to store UTC did nothing.
* Fixed: A report table whose column had been renamed took the screen down with it.
* Fixed: A date could disappear from a report with nothing to say why.
* Fixed: Deleting the plugin could stop half way through and leave the tables behind.
* Fixed: A stale or foreign transient could stop the Google connection screens drawing.
* Fixed: Every date on every screen was a day early west of Greenwich.
* Fixed: The orphaned-dimension sweep could delete dimensions that were in use.
* Fixed: Two more writes inside the drain transaction were unchecked.
* Fixed: The real-time figure on the Dashboard stopped counting at 200.
* Fixed: A drain batch whose write failed was recorded as committed.
* Fixed: A session staged for closing by a batch that never committed was never closed.
* Fixed: Compaction zeroed the imported visitor count.
* Fixed: Session deltas are applied inside the drain transaction.
* Fixed: Uninstall with "keep data" off now removes everything.
* Security: The geo database sat at a guessable, downloadable address.
* Security: A directory that lost its guard files never got them back.
* Security: The Google client secret is encrypted at rest.
* Security: The connection broker address is pinned to https.
* Security: Pro only: The update check pins download_link as well as package.
* Security: The GA4 property is validated rather than sanitised.
* Security: The user agent and the referrer are reduced in the request that saw them, not at the drain.
* Security: The Cloudflare address source is verified rather than trusted.
* Security: Pro only: An update is only installed from the licence server.
* Security: Google refresh tokens are encrypted at rest.
* Security: The geo database download refuses private addresses and has a size ceiling.
* Security: A second beacon rate limit, on the address alone.

= 0.5.0 =
* Added: Pro only: client-shareable reports are downloadable as a PDF, from the Shared reports screen or from the report itself.
* Added: Pro only: a share link's content is now a choice - Trend, Top pages, Sources, Devices and Content can each be switched on or off per link, applied identically to the page and the PDF.
* Added: Pro only: This month and Last month join the rolling windows a share link can show, for a report that names a real calendar month.
* Added: Pro only: a client-shareable report can carry an agency's own name in place of the site's own, set once on Settings.

= 0.4.0 =
* Changed: Search Console now has the same hosted-broker groundwork Google Analytics already had. Neither broker filter is set anywhere, so every site keeps connecting with its own Google Cloud client exactly as before.

= 0.3.0 =
* Added: Pro only: client-shareable reports - a link an administrator creates showing a rolling window of the overview, with no WordPress account needed, individually revocable, with an optional expiry.
* Added: Pro only: traffic spike and drop alerts, off by default - once a day, yesterday is compared against a typical figure for that weekday, with the real numbers stated in the email.
* Added: Pro only: click tracking on any element, marked with a data-honest-event attribute or a configured CSS selector, recording only the event name.
* Added: Pro only: Search Console query data - what people searched to reach a page, imported through its own narrowly scoped Google connection and shown apart from this plugin's own figures.
* Fixed: the "See what Pro adds" link on a locked report went to the wrong domain and landed on a parking page instead of the pricing page.

= 0.2.3 =
* Added: comparison now shows on the Devices and Sources screens, and every ranked table (Pages, Devices, Sources) shows a per-row change alongside the headline figures.
* Added: Locations shows full country names, a shaded world map, and a breakdown by region.
* Added: Pro only: the Licence screen links to the account area at pro.honest-analytics.com.

= 0.2.2 =
* Pro only: activating, deactivating and checking a licence now calls a real licence server, instead of taking a well-formed key's word for it. A network problem still never takes Pro away from a site that already had it.
* Pro only: updates for the Pro build now come from the same server, through the normal WordPress update screen.
* Pro only: fixed the "View version details" link, which checked the free edition's slug regardless of which one was installed and so never opened for a Pro site.

= 0.2.1 =
* Fixed: "No comparison" still showed a percentage change against the previous period on every headline figure. It is now silent about change when no comparison is active.
* Fixed: the comparison line's tooltip and legend named only the period, which read as its own metric. It now names the metric too, e.g. "Pageviews vs same period last year".

= 0.2.0 =
* Added: a date range spanning more than one year now shows the year on the chart.
* Added: trend charts can be grouped by day, week, month or year, independently of the date range.
* Added: compare a period against the one before it, or the same period last year, with a second line on the chart and a percentage change on every headline figure.
* Added: the date range, grouping and comparison chosen on one screen now follow you to the next.
* Added: the write-spool warning can now be dismissed for thirty days at a time.
* Changed: rollup retention now defaults to thirty-six months, up from twenty-six.
* Fixed: history brought in from Google Analytics, WP Statistics or Independent Analytics is now kept regardless of the retention setting, instead of being deleted the night after import.
* Fixed: a day mixing native traffic with already-imported history could be merged into one row by the nightly tidy-up, losing which source it belonged to.
* Fixed: the Google Analytics import range step could claim a property had one day of data when it had years.

= 0.1.2 =
* Fixed: a failed Google Analytics connection said one generic sentence for every cause. A mismatched redirect address, an unrecognised Client ID or secret, and a Google Analytics API that is not switched on yet now each say which one it was.
* Fixed: a wrong Client ID or secret looked exactly like an expired connection, and told you to reconnect - which failed again the same way. It now says the sign-in details do not match.
* Added: the Google setup guide links straight to enabling each required API, instead of sending you to search for it.
* Added: the Client ID field flags an obviously wrong paste before it round-trips through a Google error.

= 0.1.1 =
* Fixed: connecting to Google Analytics went to the dashboard and stopped, because the redirect to Google's sign-in screen was treated as though it were a link back to your own site.
* Fixed: every outcome of that connection was silent. A cancelled sign-in, a misconfigured Google project and a successful connection now each say what happened.
* Fixed: on the free edition, the admin could log a PHP warning on every page in the Analytics menu.
* Added: the paid reports now keep their place in the menu, marked, each explaining what it contains. No figures are shown, invented or otherwise.
* Added: full setup instructions for Google Analytics, in the plugin and in the documentation.
* Added: the geo database installs from the Locations screen, and maintenance runs from buttons on Settings, so neither needs a terminal.
* Added: the offer to import from another analytics plugin now goes away once you have taken it up.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.9.0 =
Adds an All time range, and corrects a heatmap caption that named the retention setting rather than the days it had drawn. No change to how anything counts.

= 0.8.5 =
Packaging and code-standards corrections found by the plugin directory's own checker. No change to how anything counts.

= 0.8.4 =
Corrects the stated size of the tracking script on the Settings screen. No change to how anything counts.

= 0.8.0 =
Several import and drain fixes, including native and imported rows that could be written to each other's row. Worth taking if you have imported history.
