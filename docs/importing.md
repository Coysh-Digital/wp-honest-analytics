# Bringing your history with you

If you have been measuring your traffic with something else, you do not have to
start from zero. Honest Analytics can import your history from:

- **WP Statistics**
- **Independent Analytics**
- **Google Analytics 4**

Everything below happens in **Analytics → Import data**.

---

## Why your numbers will not match exactly

This is the important part, so it is first rather than in a footnote.

Analytics tools do not all count the same things. They disagree about what
counts as a visitor, when a session starts and ends, which robots to ignore,
what to do about ad blockers and privacy settings, which timezone a day belongs
to, and whether somebody who comes back tomorrow is the same person.

So when you import history from another tool and then keep measuring with this
one, **you will probably see a change in the numbers around the day you
switched**. Nothing has gone wrong. The measuring instrument changed.

What imported history is good for:

- long-term trends
- which content has always done well
- comparing this month with the same month last year
- seeing that a redesign or a campaign moved the needle

What it is not:

- a guarantee that 14 March 2023 will show the same visitor count it showed in
  the other tool

The screen says this before every import, and the details screen marks each
metric as either an exact match or an approximation, with the reason.

### The specific reason, most of the time

Honest Analytics identifies a visitor with a hash of a secret that is
**destroyed every twenty-four hours**. That is what lets it count people without
storing anything about them - and it means a "unique visitor" here is a *daily*
unique. Somebody who visits on Monday and Tuesday is two.

Other tools keep an identifier for months. Their visitor totals are therefore
built on a different definition of the word, and will usually be lower over a
long period. Page views, which everybody defines the same way, line up much
better.

---

## Importing from WP Statistics

1. Open **Analytics → Import data**.
2. WP Statistics should already be listed as found. You do not need to have it
   activated - if its data is still in the database, that is enough.
3. Read the note about numbers, then choose how much history to bring across.
   All of it is the default.
4. Check the preview: the dates found, roughly how much data, and what will be
   imported.
5. Choose **Start import**.
6. You can leave the page. The import continues in the background, and you can
   come back to watch it or ignore it entirely.
7. When it finishes, your dashboard includes the history.

**Nothing in WP Statistics is changed, moved or deleted.** The import only
reads. You can carry on using it, or turn it off, whenever you like.

**What comes across:** page views, visits, visitors, referrers, countries,
devices and browsers - whichever of those your version of WP Statistics
actually kept. The preview lists what was found on your site rather than what
the plugin can do in principle.

Browsers and operating systems are **not** imported from Google Analytics, for
a reason worth knowing: GA4 groups them under its own names, so "Chrome" from
GA4 sitting beside a natively measured "Chrome 126" would look like two
different browsers rather than one. The local importers do bring them across,
because their values line up.

---

## Importing from Independent Analytics

The steps are the same:

1. **Analytics → Import data**.
2. Independent Analytics should be listed as found. It does not need to still be
   active; its tables being present is enough.
3. Read the note, choose a range, check the preview.
4. **Start import**, then leave the page if you like.

**What comes across:** views, visitors, sessions, referrers, countries and
devices, again depending on what your version stored.

Independent Analytics has its own way of deciding what a visitor and a session
are, so those figures may shift a little at the switchover. Page views should
line up closely.

---

## Importing from Google Analytics

GA4 keeps your data on Google's servers, so this one needs a connection rather
than a database read.

1. Open **Analytics → Import data**.
2. Choose **Google Analytics**.
3. Choose **Connect Google Analytics** and sign in with the Google account that
   can see the property.
4. Pick the property. Properties are grouped by account and listed by name and
   website address, so you should not need to know the number. If the account
   has a great many properties the addresses are left off the list - fetching
   each one means another request to Google - and the address of whichever you
   choose is shown on the next step instead.
5. Choose how much history to bring across. The screen shows the range Google
   has for that property.
6. Read the note - this one matters more than the others, see below.
7. **Start import.**
8. Leave the page. GA4 imports take longer than local ones because the data
   comes over the internet a chunk at a time, and Google limits how quickly it
   can be read.
9. Come back whenever you like. Progress is saved as it goes.

Only permission to **read** your analytics is requested. Nothing is written back
to Google, and the connection can be removed at any time from the same screen.

If your Google Analytics property reports in a different timezone from this
site, the import says so before it starts. There is nothing to fix - a daily
total cannot be re-cut into a different day - but a day's figures may sit a day
either side of where you would expect, and it is better to know that in advance
than to wonder about it later.

### The GA4 caveat, plainly

GA4 counts with Google's own definitions of an event, a user and a session.
Its **Active Users** figure is not the same thing as a visitor measured here,
and the gap is usually noticeable rather than slight.

So expect visitor and session numbers to step up or down on the day you
switched. Page views will track much more closely. The long-term shape of your
traffic - which is what history is for - comes across intact.

---

## Running an import more than once

Safe. Importing the same dates again **replaces** what was imported before
rather than adding to it, so a retry after a failure cannot double your
history.

If more history has appeared since - you imported last year and the old plugin
has kept running - you can import again and only the new dates are added.

## If you have used more than one tool

Say WP Statistics from 2020 to 2023 and GA4 from 2022 onwards. Those dates
overlap, and adding both would count 2022 and 2023 twice.

The import screen notices, shows you which dates clash, and asks. The default is
to import only the dates that are not already covered, which is the safe answer.
You can instead replace the overlapping dates with the new source, or stop.

**Nothing is ever double-counted silently.**

## If something goes wrong

Imports are resumable. A timeout, a server restart, a lost connection or Google
limiting how quickly it will answer will pause the import rather than lose it,
and it picks up where it stopped.

If you see a message saying an import has been paused, it usually needs nothing
from you. If it has genuinely failed, the details screen has a plain
explanation, and the technical detail is in the log for support.

## Removing imported history

Imported data stays until you remove it deliberately. Deactivating or deleting
the plugin you imported from does not touch it, and neither does anything else.

## Where imported data shows up

In the normal dashboard, alongside everything else - there is no separate screen
for it. Where your imported history meets data collected here, the traffic chart
marks the switchover, so a step in the line has a visible explanation rather
than looking like a bug.

The **Import data** screen keeps a record of every import: what was brought
across, from where, covering which dates, and which metrics were exact matches
rather than approximations.
