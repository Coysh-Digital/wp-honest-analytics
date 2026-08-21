# Page caches

Honest Analytics counts cached pages correctly and needs no cache-plugin
integration to do it. This document explains how, and what to check.

## How a cached page gets counted

In `hybrid` mode - the default - the server counts the requests it sees, and a
small tracker confirms the rest from the browser. The two are reconciled with a
nonce that is consumed **once per visitor**, not once per nonce.

That distinction is the whole mechanism. A cached page is served to thousands
of people with the same nonce baked into it. Consuming the nonce once would
count the page once, ever. Consuming it once *per visitor hash* counts each
visitor once - which is what "one nonce per pageview" was always trying to
express.

So:

- Cached HTML → PHP never runs → the beacon counts it. ✅
- Uncached HTML → PHP counts it → the beacon's nonce is already spent for this
  visitor and is ignored. ✅
- Same visitor, two loads of the same cached page → two beacons, one nonce, one
  visitor → **one** view. ✅
- Two visitors, identical cached HTML → two beacons, one nonce, two visitors →
  **two** views. ✅

There are integration tests for all four.

## What you do not have to do

- No cache exclusions for any page.
- No fragment caching, ESI, or "hole punching".
- No cookie that varies the cache key.
- No cache plugin add-on.

## What you should check

### The beacon must not be deferred into oblivion

Optimisation plugins with a "delay JavaScript until interaction" feature will
happily delay the tracker until the visitor clicks something - and a visitor
who reads and leaves never clicks. `Integrations\OptimizerExclusions`
registers the tracker handle with the exclusion filters of WP Rocket,
Autoptimize, LiteSpeed Cache, SG Optimizer and Perfmatters, and the script tag
carries `data-no-optimize`, `data-cfasync="false"` and `nowprocket`.

WP Rocket's Delay JS is the one that still needs a look, because its exclusion
list is a text field rather than a filter. Settings → Data writing raises a
notice when it detects Delay JS enabled without our handle excluded.

### The collect endpoint must not be cached

It answers `Cache-Control: no-store` and sets `DONOTCACHEPAGE`,
`DONOTCACHEOBJECT` and `DONOTCACHEDB`, plus LiteSpeed's no-cache header. Every
cache plugin tested respects that. If yours does not, exclude
`/wp-json/honest-analytics/v1/collect` explicitly.

### The REST API must be reachable

Some security plugins disable `/wp-json/` wholesale. `Rest\RestUnlock` exempts
the two public routes and nothing else. If the REST API is disabled at the
server rather than in PHP, switch `collectEndpoint` to `plain`, which uses
`/?honest-analytics=collect` instead.

You do not have to check this by hand. Once a day the site asks itself for its
own collection endpoint and raises a fault on the Settings screen if the answer
is not a 204. The request goes to this site and nowhere else.

### nginx must protect the spool directory

Apache and IIS are handled by the `.htaccess` and `web.config` written at
activation. nginx cannot be configured from PHP, so add this once:

```nginx
location ~* /wp-content/uploads/honest-analytics/ {
	deny all;
	return 404;
}
```

The spool contains no addresses, but it is not public data.

The same daily loopback check writes a probe file into the spool directory,
asks the web server for it, and deletes it. If the probe comes back, the
Settings screen says so in as many words rather than assuming the `.htaccess`
did its job. `dev/setup.sh` writes the rule above into the ddev project, so the
development harness is configured the way a real server should be.

If the check cannot complete - a loopback that never resolves, a self-signed
certificate - it reports "unknown" rather than a clean bill of health. A
security check that fails open is not a security check.

## Tested against

WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Cache Enabler,
Batcache, SG Optimizer, NitroPack, Cloudflare (full-page and APO), and Varnish
in front of any of them. `Integrations\CacheDetector` names whichever it finds
on the Settings screen.

## Server-only mode and caches

`trackingMode = server` does not see cached pages, because it only sees
requests that reach PHP. On a fully cached site it will report a small
fraction of real traffic. The Settings screen says so next to the option, and
the Dashboard shows a hint when it detects a page cache alongside server-only
mode. If you need server-only for policy reasons, exclude the pages you care
about from the cache, or accept the undercount knowingly.

## Verifying

```bash
# Two visitors, one cached page. Expect 2 views, 2 uniques.
UA1="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
UA2="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36"
NONCE=$(curl -s -A "$UA1" -H 'Accept-Language: en-GB' https://example.com/ | grep -o 'data-nonce="[^"]*"' | cut -d'"' -f2)

curl -s -o /dev/null -A "$UA1" -H 'Accept-Language: en-GB' -X POST \
  https://example.com/wp-json/honest-analytics/v1/collect -d "p=/&n=$NONCE"
curl -s -o /dev/null -A "$UA2" -H 'Accept-Language: en-GB' -X POST \
  https://example.com/wp-json/honest-analytics/v1/collect -d "p=/&n=$NONCE"

wp honest-analytics drain
wp honest-analytics info
```
