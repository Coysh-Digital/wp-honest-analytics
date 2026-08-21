# Scheduling

**You do not need cron.** The plugin is built to work on hosting where you
cannot add a scheduled task, and that is a supported arrangement rather than a
degraded one. If you can add one, it makes the figures a little fresher on a
busy site, and this page explains how - but nothing here is a prerequisite.

## What runs, and what runs it

| Job | With cron | Without cron |
|---|---|---|
| Turning spooled views into totals | every 5 minutes | ordinary page views, at most once a minute |
| The nightly tidy-up | daily | the first admin page load of the day |
| Rotating the identity salt | daily | the next pageview after it falls due |
| Advancing an import | every minute while one is running | the import screen while it is open, then admin page loads |

Nothing waits for a schedule that may never come. Each of those jobs knows how
to notice it is due and get on with it.

## Without cron, in detail

**Counting.** A view is written to a spool the moment it happens - that part
never waits for anything. Turning the spool into totals is the part that
happens afterwards, and on a site without cron an ordinary request does it: at
most once a minute, after the visitor already has their page, and for at most
five seconds at a time. A large backlog is taken a bite at a time rather than
all at once, so no single visitor ever pays for the whole thing and the figures
catch up over the next few page views.

**The tidy-up.** Compaction and retention run from the first admin page load of
any day they have not already run. The admin is never page-cached, which makes
it the most reliable clock a site without cron has.

**The salt.** Rotation is lazy. The salt carries the time it is next due, and
the next pageview after that time replaces it. A site with no traffic and no
cron rotates its salt the moment traffic returns, which is the only moment it
matters.

**Imports.** While the import screen is open, the browser drives it - that is
the fastest route and the one with a progress bar. Close the tab and admin page
loads pick it up instead. It will be slower, and it will finish.

### The one thing to watch

If page views are not clearing the backlog - a very busy site, or a spool that
cannot be written - the Settings screen says so. That is a real fault worth
looking at, and it is reported differently from "there is no cron here", which
is not.

## With cron, if you can

Two ways, either is fine.

**Leave WordPress to it.** Nothing to do. WP-Cron fires on page loads, which on
most sites is often enough, and the fallbacks above cover the gaps.

**A real scheduled task.** More predictable, and worth it on a busy site:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
*/5 * * * * cd /path/to/wordpress && wp honest-analytics drain --quiet
0   4 * * * cd /path/to/wordpress && wp honest-analytics gc --quiet
```

Or drive all of WordPress's scheduled work rather than only this plugin's:

```cron
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

Settings prints whichever line applies to your setup behind a "Show the cron
line" toggle, so it can be copied rather than transcribed.

### On a network

Cron is per site. One line for the whole network:

```cron
*/5 * * * * cd /path/to/wordpress && wp honest-analytics drain --network --quiet
```

## Running things by hand

### From the browser

Settings has a **Run maintenance now** card with two buttons, and no terminal
is involved in either:

| Button | What it does |
|---|---|
| Drain now | Folds whatever is waiting in the spool into the reports. Stops after twenty seconds and picks up where it left off, so a large backlog clears over a few presses. |
| Run the tidy-up | Compacts finished days and deletes anything past its retention. This is the one that acts on the retention settings, and what it deletes does not come back. |

A third button, **Retry set-aside batches**, appears only when there is
something set aside to retry.

### From WP-CLI

```bash
wp honest-analytics drain            # once
wp honest-analytics drain --watch    # follow, until interrupted
wp honest-analytics drain --retry    # include quarantined files
wp honest-analytics gc               # retention and compaction
wp honest-analytics gc --dry-run     # count what would go, delete none of it
wp honest-analytics salt rotate      # force rotation now
wp honest-analytics info             # everything above, as a report
```

`drain` takes a database lock, so a manual run, a cron run and a page view
cannot collide. Whichever arrives second exits immediately rather than waiting.

## When a drain fails

Failures are per file, not per line. A file that fails three times is moved
aside rather than retried forever, and `info` lists it. Nothing is deleted.

```bash
wp honest-analytics info
wp honest-analytics drain --retry
```

Or press **Retry set-aside batches** in Settings, which appears once there is
something to retry and does the same thing.

If it fails again, the file is readable NDJSON - one JSON object per line, no
addresses in it - and the failure is in the debug log with the batch id.
