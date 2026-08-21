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
than a database read. That takes a one-off setup on Google's side first, which
is the fiddliest part of this whole plugin and is written out step by step
below.

### First, tell Google that this site may ask

Google will not let anything read your analytics until you have registered the
thing doing the asking. You do this once, it is free, and no card is involved.
Allow about five minutes.

Everything below is also printed on the Import screen itself, with your site's
own values filled in, so you can work from there and use this page when
something does not match.

**1. Copy the redirect address from your own site.**

Open **Analytics → Import data → Google Analytics**. Near the top of the setup
there is a read-only box with a Copy button beside it. It looks like this:

```
https://example.com/wp-admin/admin-post.php?action=honest_analytics_ga4_callback
```

Copy it rather than typing it. Google matches this character for character
later, including `www` or its absence, `http` against `https`, and the whole
`?action=` part.

**2. Make a Google Cloud project.**

Go to <https://console.cloud.google.com/projectcreate> and sign in with the
Google account that can already see the analytics you want. Create a project;
the name is only for you.

**3. Switch on the two APIs it reads through.**

In the console, **APIs and services → Library**, search for and enable both:

| API | What it is for |
|---|---|
| Google Analytics Admin API | Listing your accounts and properties |
| Google Analytics Data API | Reading the actual figures |

Miss the first and your property list comes back empty. Miss the second and the
import fails as soon as it starts.

**4. Fill in the consent screen.**

Under **Google Auth Platform**, or **APIs and services → OAuth consent screen**
on older consoles. You need an app name and your own email address twice.

- **User type.** Choose **Internal** if your Google account belongs to a
  Workspace. It is simpler and avoids the seven-day limit below.
- Otherwise choose **External**, and add your own Google address under **Test
  users**. An External app that has not been published only works for accounts
  listed there.
- **Scope.** Add `https://www.googleapis.com/auth/analytics.readonly`. It is the
  only one this plugin asks for, and it is read-only.

**5. Create the sign-in details.**

**Credentials → Create credentials → OAuth client ID**.

- **Application type: Web application.** Not Desktop. The sign-in is a browser
  redirect back into wp-admin.
- Under **Authorised redirect URIs**, choose **Add URI** and paste the address
  from step 1.
- Create it. Google shows you a **Client ID** and a **Client secret**.

**6. Paste them into WordPress.**

Back on **Analytics → Import data → Google Analytics**, put both in and save.
They are stored on this site with autoloading off. The secret is never sent to
a browser, never appears on a REST route, and is never written to a log.

**7. Connect.**

Choose **Connect Google Analytics**. Google will warn you that it *has not
verified this app*. That is expected and is not a fault: read-only analytics
access counts as a sensitive scope, and the "app" is the project you made five
minutes ago for your own site. Choose **Advanced**, then **Go to (unsafe)**, and
approve.

### When it does not work

The screen now tells you which of these happened, in words. The three common
ones:

| What you see | What it means |
|---|---|
| Google refused the connection | The redirect address in your Google project does not match the one on the Import screen, or the two APIs are not switched on yet |
| There are no Google sign-in details saved | The Client ID and secret have not been saved on this site |
| The reply from Google did not match | The attempt sat around too long, or was finished in a different browser or tab. Press Connect and go straight through |

Some other things worth knowing before you start:

- **Plain `http://` will not work.** Google rejects a redirect address that is
  not HTTPS unless the host is `localhost` or `127.0.0.1`. A local site at
  `http://example.test` cannot complete the sign-in; use `localhost` with a port,
  or put an HTTPS tunnel in front of it.
- **External plus Testing expires after seven days.** Fine for a one-off import.
  Annoying if you are testing over weeks. Internal, or publishing the app,
  removes the limit.
- **The Google account needs at least Viewer** on the property, and it must be a
  GA4 property. There is no Universal Analytics path; that data is gone from
  Google's side.
- **You need the "manage analytics" capability** on this site, which
  administrators have by default.
- **There is no WP-CLI command for imports.** This one is the admin screen only.

### Then the import itself

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
