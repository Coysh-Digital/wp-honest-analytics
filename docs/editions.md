# Editions and licensing

What Lite, Pro and Agency include, how a licence key becomes an edition, and
which commercial decisions are still open.

Nothing here invents a value that has not been decided. Anything marked **TBC**
is genuinely undecided and is not hard-coded anywhere in the plugin.

---

## The three editions

| | Lite | Pro | Agency |
|---|---|---|---|
| Distribution | wordpress.org | direct download | direct download |
| Licence | none, no key | one-off key | one-off key |
| Sites | unlimited, no activation tracking | 1 | unlimited (cap **TBC**) |
| Price | free | **TBC** | **TBC** |

**Getting hold of them.** Lite is on the wordpress.org plugin directory, so the
route most people already know works: **Plugins → Add New** in the admin,
search for **Honest Analytics**, then **Install Now** and **Activate**. Nothing
to download by hand and updates arrive the ordinary way. Pro and Agency are a
zip from the checkout success page, installed through **Plugins → Add New →
Upload Plugin**; updates arrive in the same admin screen once the key is
entered. [`installation.md`](installation.md) has the steps for both.

**Pro and Agency are the same build.** The licence key decides the activation
limit and nothing else. There is no Agency codebase, no Agency-only feature, and
no code path that asks "is this Agency" for any purpose other than reporting the
site count on the Licence screen.

Lite is a **separate build** from the same source tree, with the Pro source and
templates stripped rather than disabled - wordpress.org will not accept a
package full of paid code sitting behind a key.

```bash
bash bin/build-lite.sh    # slug honest-analytics,     580 KB, 321 files, 156 classes
bash bin/build-pro.sh     # slug honest-analytics-pro, 636 KB, 354 files, 176 classes
```

`bin/pro-manifest.txt` is the difference between them: a readable list of what
is Pro-only, what legitimately mentions a stripped class from behind an edition
check, and what ought to be Pro-only but cannot be stripped yet, each with the
reason. `bin/check-lite-build.php` refuses to package a Lite build that still
reaches for something the strip removed, telling load-time references
(`extends`, trait `use`, constant initialisers) apart from runtime ones - and
`bin/check-classes-load.php` then autoloads every class in each staged build,
because parsing is not booting.

`HONEST_ANALYTICS_HAS_PRO` is stamped at package time and identifies the build.
The working tree defines nothing, and undefined means Pro, which is what a
developer wants.

## Commercial terms

**One-off payment.** No subscription, and the licence has no expiry date.

**Updates** are included indefinitely, delivered through the normal WordPress
update screen.

**Support** is email, included for twelve months from purchase. A paid extension
after that is **TBC** and may be dropped.

The consequence for the code, and it is not negotiable: **nothing may be
disabled when support lapses.** A licence has no functional expiry. If a
support-expiry date is stored at all, it affects messaging and nothing else.
There is a comment saying so at the place somebody would otherwise be tempted.

VAT is calculated at checkout by the commerce provider (**TBC**). The refund
policy is **TBC**.

## What is in each

**Lite** - and it is a product, not a trial:

- Pageviews and daily unique visitors
- Sessions and bounce rate
- Real-time visitors
- Content performance, by post type, taxonomy and author
- Sources and devices
- CSV and JSON export
- The Privacy screen and the Settings screen
- The WordPress-native surfaces: both dashboard widgets, the Views column on
  post lists, the analytics panel in the editor

**Pro and Agency add:**

- Campaigns and locations
- Events, goals and funnels
- Crawler reporting
- Third-party integrations: Contact Form 7, Gravity Forms, WooCommerce, WPForms
  and Ninja Forms
- Consented durable tracking and stored journeys
- Scheduled email summaries

Two decisions worth recording, because both went the other way at some point:

- **Export is in Lite**, both formats. A plugin whose premise is that the data
  is yours cannot withhold the download button for data the site collected
  itself.
- **Crawler reporting is in Pro.** Lite still excludes crawlers from every
  figure; it just does not show the breakdown.

## What Lite must never do

No nag screens. No countdowns. No expiry warnings. No "most popular" or "best
value". No artificial limits - not on rows, not on retention, not on date
ranges.

The paid reports do keep their rows in the Analytics menu, marked `Pro`, each
leading to a page describing what that report contains. That is discovery, not
a sales pitch, and the difference is defended by three rules:

- **No figures on those pages, real or invented.** A mocked-up table with
  plausible numbers would be read as the site's own data.
- **One link, no button, no price.** The page says what the report answers,
  lists what it holds, and links once.
- **Nothing that returns.** There is nothing to dismiss, because nothing
  interrupts. The pages sit where they sit and wait to be visited.

The pages also say plainly that the free build does not contain the report at
all - it is removed when the plugin is packaged, not hidden behind a check -
which is both true and the reason the arrangement is permitted at all. See
[ADR 57](architecture.md#adr-57--the-paid-reports-are-named-in-the-free-menu-and-described).

The Pro placeholder cards on the Dashboard say what the section would contain,
once, quietly, and link to the same pages.

The same standard binds the rest of the admin UI. See
[Copy constraints](#copy-constraints).

## Data, upgrading and downgrading

All data lives in the site's own WordPress database. Nothing is sent to Coysh
Digital or to any third party, in any edition, ever.

**Pro reads the same tables Lite writes.** Table names come from
`Schema\Tables` and are `{$wpdb->prefix}honest_*`; nothing derives them from the
plugin slug or the edition. Upgrading never migrates, truncates or re-keys
anything, so history collected under Lite appears unchanged in Pro.

**Downgrading is equally safe.** The tables stay; the Pro-only reports simply
stop being rendered. Nothing is deleted when a licence is deactivated, and
nothing is deleted when Pro is replaced by Lite.

**The two builds must not run at once.** Installing Pro deactivates Lite and
says so in a notice that states no data was touched.

**Uninstall keeps the tables by default.** See
[`uninstall.md`](uninstall.md) - the destructive option is one somebody chooses.

## Privacy constrains every Pro feature

The default configuration sets no analytics cookie, stores no IP address, and
derives anonymous identity from a salt that rotates and is destroyed every 24
hours.

The consequence for Pro features is absolute: **unique visitors are daily
estimates, not people.** No cross-day or lifetime unique-visitor metric can be
honestly produced from this model, so none exists. Every Pro report that shows
the number describes it that way.

Any Pro feature that would need a cookie, a persistent identifier or a
longer-lived salt must be:

1. opt-in,
2. off by default, and
3. explicit in the UI that enabling it changes the privacy posture, and may
   require consent.

That applies to funnels and to anything resembling a returning-visitor metric in
particular: design within the 24-hour identity window unless the operator has
opted in with their eyes open. The Settings screen carries an amber
"Consequence:" line on every switch that does this.

## The licence layer

The commerce and licensing provider is **TBC**. Freemius, Lemon Squeezy and
WooCommerce with a licensing add-on were the candidates. **No provider SDK,
endpoint or payload shape is hard-coded anywhere.**

Everything sits behind `Licensing\LicenceProviderInterface`, so the provider can
be swapped without touching a single gate:

| | |
|---|---|
| `name()` | what to show as "checked by" on the Licence screen |
| `activate( key, siteUrl )` | status, activation count, limit, support-expiry |
| `deactivate( key, siteUrl )` | releases the activation |
| `check( key )` | periodic revalidation, cached, with a generous grace period |
| `updateCheck( key )` | the signed update package for the licensed build |

Each returns a `LicenceState`: an immutable value object carrying the tier,
status, activations used, limit, support expiry, when it was last checked and
whether the answer came from cache. It deliberately has **no `isExpired()`** -
the docblock says why, so the next person is not tempted to add a gate.

`OfflineProvider` is the default and makes no network request at all. Swapping
it in is one filter:

```php
add_filter( 'honest_analytics_licence_provider', fn () => new MyProvider() );
add_filter( 'honest_analytics_portal_url', fn () => 'https://…/account' );
```

Revalidation runs on `shutdown` in the admin rather than on cron, throttled to
a fortnight with a six-hour retry backoff. Nothing expires, so a re-check can
only ever discover a refund or a moved seat; the page renders first and the
request goes out afterwards.

### Fail open, always

A network failure, a timeout, a 500 from the provider or a DNS outage **must
never disable Pro features**. The last known good state is cached and kept in
use; revalidation retries quietly on a schedule. A site whose owner paid once
does not lose its reports because somebody else's server is down. There is a
test that makes the provider throw and asserts the edition does not change.

### The Licence screen

One screen: **Analytics → Licence**. Key field, activation status, sites used
against the limit, support-expiry date, deactivate button. It is registered only
in the Pro build; a Lite install has no key and no licence screen.

There is no account area on the marketing site. Receipts, licence management and
renewals link to the provider's customer portal.

## Purchase flow

Pricing CTA → provider-hosted checkout → success page → download, licence key
and install steps.

The success page shows what was purchased, a download action, the licence key,
three install steps, a documentation link, a support link, and a
receipt / licence-management link to the provider portal.

## Copy constraints

The product is called Honest Analytics, which sets a standard the admin UI has
to meet as well as the marketing site:

- No fake urgency, countdowns, or scarcity.
- No "most popular" or "best value" labels without evidence.
- **No blanket "GDPR compliant" or "no cookie banner needed" claims.** The safe
  wording is *cookieless by default* and *designed not to require an analytics
  consent banner in its default configuration*.
- Qualify unique visitors as **daily estimates** wherever the number appears -
  the Dashboard, the page detail, both widgets, the editor panel, the email
  summary and the CLI reports all do.
- Be explicit about limitations in the UI rather than hiding them.

The Privacy screen's posture card follows the last of these literally: it states
what the configuration permits, notes that this is not legal advice, and says
whose conclusion the compliance question actually is.

## Still to decide

| | |
|---|---|
| Pro price | TBC |
| Agency price | TBC |
| Agency activation cap | TBC - read from the provider's response, never hard-coded |
| Commerce and licensing provider | TBC |
| Paid support extension, and its price | TBC, may be dropped |
| VAT handling | TBC - calculated at checkout by the provider |
| Refund policy | TBC |

None of these blocks the plugin. The licence layer works offline today and the
provider is a matter of implementing one interface.
