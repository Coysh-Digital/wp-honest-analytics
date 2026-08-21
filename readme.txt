=== Honest Analytics ===
Contributors: coyshdigital
Tags: analytics, privacy, statistics, cookieless
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless analytics that live in your WordPress admin. No third-party service, no IP addresses stored, no per-visitor rows.

== Description ==

Honest Analytics counts your traffic and shows it in the WordPress admin. Nothing is sent anywhere, and nothing is stored that could identify a visitor later.

= What it never stores =

* **No IP addresses.** Not in a table, not in a log, not in a cache key, not in the write spool. The address exists as a variable inside one function, is hashed, and is gone.
* **No full referrer URLs.** Only the host. `?token=…` never reaches disk.
* **No full user-agent strings.** Parsed into browser, operating system and device type, then discarded.
* **No raw pageview records.** Reports are built from aggregate rollups.
* **No cookies** in the default configuration.
* **No third parties.** No telemetry, no CDN, no fonts, no map tiles. The free edition makes no outbound request at all.

= How it counts people without identifying them =

A visitor is a short hash of a random daily salt, the address, the user agent and the site. The salt is overwritten in place every 24 hours, so yesterday's hashes cannot be recomputed by anybody - including somebody holding your database.

Uniqueness is estimated from a fixed-size sketch stored on the rollup row, accurate to about ±1.6%. It holds no identifiers and cannot be asked whether it contains a particular person.

This is why **unique visitors are daily estimates**, not people, and why the plugin says so on every screen that shows the number. The same person visiting on three days counts three times. There is no honest way to produce a cross-day or lifetime unique-visitor figure from this model, so the plugin does not offer one.

= It works behind a page cache =

The server counts the requests it sees. A 1.3 KB first-party script confirms the rest from the browser. A nonce reconciles the two - consumed once per *visitor*, not once per nonce - so one piece of cached HTML served to a thousand people counts a thousand times.

No cache exclusions. No hole punching. No cache-plugin add-on. Tested with WP Rocket, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler, SG Optimizer, NitroPack and Cloudflare.

= It respects opt-out signals =

A request carrying `Sec-GPC: 1` is not counted, not queued, and is sent no tracker at all. On by default. Do Not Track is supported the same way, off by default.

= Storage that does not grow with your traffic =

Storage grows with dimensions × time, not pageviews × time. A site with a hundred thousand views a day uses roughly the same disk as one with a hundred. Hourly detail is kept for a week, then compacted to daily; everything is deleted at 26 months at the latest.

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
3. If your site is behind a full-page cache, read the Scheduling documentation - WP-Cron barely runs on a cached site, and aggregation needs a real schedule.

Requires WordPress 6.4+, PHP 8.1+, MySQL 5.7+ or MariaDB 10.4+.

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

No. The free edition makes no outbound request, for any reason.

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

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
