# Privacy

The short version: Honest Analytics counts people without identifying them,
keeps nothing that could later identify them, and asks permission before it
keeps anything durable.

The rest of this document is that claim, itemised, so it can be checked rather
than believed.

Nothing here is legal advice. It describes what the software does, which is
what a lawyer will need in order to tell you what it means for you.

## What is stored

| Stored | Shape | Where |
|---|---|---|
| Normalised page path | `/pricing` | `honest_pages_rollup` |
| Referrer **host** | `example.com` | `honest_dimensions` |
| Channel | `organic`, `social`, `direct`, … | `honest_sources_rollup` |
| Device type | `desktop`, `mobile`, `tablet` | `honest_devices_rollup` |
| Browser family | `Chrome` | `honest_devices_rollup` |
| Operating system family | `macOS` | `honest_devices_rollup` |
| Language | `en-GB` | `honest_dimensions` |
| Country, if enabled | `GB` | `honest_geo_rollup` |
| Campaign parameters | `spring-sale` | `honest_campaigns_rollup` |
| Counts and durations | integers | every rollup |
| A uniqueness sketch | a fixed-size blob | on the rollup row |

Every row is an aggregate. There is no row anywhere that represents one person.

## What is never stored

- **The address.** Not in a table, not in a log, not in a cache key, not in
  the spool, not in a queue, not hashed-and-kept. It exists as a local variable
  inside one function, is combined into a hash, and is gone when that function
  returns.
- **The full referrer URL.** Only the host survives. `?token=…` never reaches
  disk.
- **The full user-agent string.** Parsed into browser family, OS family and
  device type; the string is discarded.
- **Raw hits.** No table of pageviews. Reports are computed from rollups.
- **A durable visitor record.** There is no visitor table. Nothing survives
  the daily salt rotation.
- **Cookies**, unless you explicitly enable a consented feature that needs one.

## The identifier, and why it stops existing

Within a day, a visitor is:

```
visitorHash = first 16 hex of sha256( dailySalt | address | userAgent | siteId )
```

The salt is random, held in one row, and **overwritten in place** every 24
hours. Once overwritten, the previous day's hashes cannot be recomputed by
anybody - including someone holding the database, the address and the
user agent. There is no key to lose.

Two consequences, both stated on every screen that shows the number:

- **Unique visitors are daily uniques.** The same person on Monday and Tuesday
  is two. There is no cross-day identity, and adding two days' uniques together
  is wrong.
- Uniqueness is estimated with a HyperLogLog sketch, accurate to about ±1.6%.
  The sketch cannot be interrogated for membership; it holds no identifiers.

Rotation can be forced at any time:

```bash
wp honest-analytics salt rotate
```

Every visitor is new after that. That is not a bug; it is the guarantee working.

## Opt-out signals

**Global Privacy Control.** A request with `Sec-GPC: 1` is not counted, not
spooled, and not sent a tracker at all. On by default. Not "counted
anonymously" - not counted.

**Do Not Track.** Honoured the same way when `honourDnt` is on. Off by
default, because browsers sent DNT without asking anybody and its meaning is
no longer agreed.

A theme can tell the visitor that their signal was respected:

```php
if ( honest_analytics_gpc_detected() ) {
	echo '<p>We are not counting this visit - your browser asked us not to.</p>';
}
```

## Consent, and what it unlocks

Everything above happens without consent because none of it identifies anyone.
Three things need consent, are off by default, and cannot be enabled without a
consent mechanism configured:

| Feature | Setting | What it adds |
|---|---|---|
| Durable visitor cookie | `enableConsent` | Recognises a returning visitor across days |
| Stored journeys | `enableJourneys` | The sequence of pages one consented visitor viewed |
| Account association | `associateUserId` | Links a journey to a logged-in user |

Consent records live in `honest_consentlog`, journeys in `honest_journeys`.
They are separate tables with separate retention because they answer separate
questions: "did they agree" and "what did they do".

Cookie lifetime is capped at 24 months and journey retention at 790 days, in
the sanitizer rather than in the interface, so the cap cannot be edited away.

## Lawful basis

With defaults, no identifier persists past the day, nothing is stored per
person, and no third party is involved. Most operators treat that as outside
consent requirements entirely, on the same footing as a server log that has had
its addresses removed before writing - but the conclusion is yours and your
advisers' to reach. What the plugin can tell you is the facts, and the Privacy
screen sets them out.

The safe way to describe the default is **cookieless by default**, and
**designed not to require an analytics consent banner in its default
configuration**. Neither the plugin nor this document claims anything stronger.

Turn on any consented feature and the picture changes - you are then storing a
durable identifier, and consent is the basis. The Privacy screen states which
posture the site is currently in, with a tick or a warning per rule, and refuses
to show a tick it has not verified.

## Subject requests

WordPress's own tools work:

**Tools → Export Personal Data** returns any journey rows associated with the
address, resolved through the user account. Consent records are retained
deliberately, because a consent record is the evidence that consent was given
and erasing it destroys the audit trail.

**Tools → Erase Personal Data** removes the journeys.

Or:

```bash
wp honest-analytics privacy export --visitor-id=<hash>
wp honest-analytics privacy export --user-id=<id>
wp honest-analytics privacy erase  --user-id=<id>
wp honest-analytics privacy posture
```

With defaults there is nothing to return, because there is nothing stored about
any individual. The exporter says so rather than returning an empty file
without explanation.

## Privacy policy text

Suggested text is registered with `wp_add_privacy_policy_content()` and appears
under Settings → Privacy → Policy Guide, worded to match the posture the site
is actually in. It is a starting point, not a policy.

## Third parties

None. The plugin makes no outbound request under any circumstance: no
telemetry, no licence check, no CDN, no font, no map tile, no update ping.
Chart.js is committed to the repository with its checksum
([ADR 41](architecture.md#adr-41--chartjs-is-vendored-with-provenance)).
The GeoLite2 database, if you enable country reporting, is a file you install
yourself and is read locally.

## Checking any of this

```bash
# The spool, before aggregation. No address in it.
cat wp-content/uploads/honest-analytics/spool/*.ndjson | head

# Every table the plugin owns.
wp db query "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE '%honest%'"

# Rotate, and watch identities cease to exist.
wp honest-analytics salt rotate
```

The integration suite includes `NoIpPersistedTest`, which drives a request with
a known address and then searches every table, the key-value store, the spool
file and the debug log for it. It fails the build if the address is anywhere.
