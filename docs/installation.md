# Installation

## Requirements

| | |
|---|---|
| WordPress | 6.4 or later |
| PHP | 8.1 or later |
| Database | MySQL 5.7+ or MariaDB 10.4+ |
| Extensions | `json`, `hash`, `mbstring` (all standard) |

An object cache (Redis, Memcached) makes the write path faster and is not
required. See [ADR 29](architecture.md#adr-29--an-object-cache-is-used-when-present-and-never-required).

Nothing is fetched from the internet at any point - not during installation,
not during operation, not for licence checks.

## Installing

Three routes, in the order most people will meet them.

**From the WordPress plugin directory.** The free edition is distributed on
wordpress.org, so the usual route works: **Plugins → Add New**, search for
**Honest Analytics**, then **Install Now** and **Activate**. Updates arrive
through the ordinary update screen along with everything else. This is what
most people want, and it needs nothing else.

**From a zip.** The paid edition is a direct download rather than a directory
listing, and this is also the route for installing a specific version.
**Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, then
**Activate**. The zip already contains `vendor/`.

**From source.** For development. Clone into `wp-content/plugins/`, then
install the runtime dependencies, because the repository deliberately does not
carry `vendor/`:

```bash
cd wp-content/plugins/honest-analytics
composer install --no-dev --optimize-autoloader
wp plugin activate honest-analytics
```

## What activation does

1. Creates twenty-six `{$wpdb->prefix}honest_*` tables and records
   `honest_analytics_db_version`.
2. Grants `honest_view_analytics`, `honest_export_analytics` and
   `honest_manage_analytics` to the `administrator` role.
3. Schedules three cron events: the drain every five minutes, garbage
   collection daily, salt rotation daily.
4. Creates `wp-content/uploads/honest-analytics/spool/` with `index.html`,
   `.htaccess` and `web.config`, and writes the first salt.

Nothing else. No content is modified, no other option is touched, and no
request leaves the server.

Activation is idempotent. Deactivating and reactivating loses nothing.

## First run

Visit **Analytics** in the admin menu. The Dashboard will be empty, which is
correct - there is no historical data to import.

To confirm the pipeline end to end, from a browser that is not logged in:

1. Load a page on the front end.
2. `wp honest-analytics drain`
3. Reload the Dashboard.

If the view does not appear, `wp honest-analytics info` prints which write
driver and which stores are in use, how large the spool is, and when the drain
last ran. Almost every "no data" report is one of the cases in
[`dev/README.md`](../dev/README.md#traps-when-testing-capture-by-hand) - most
often that you are logged in, and `excludeLoggedIn` is on by default.

## Settings worth looking at on day one

Defaults are the privacy-preserving option in every group, so the plugin is
safe to leave alone. These four are the ones that depend on your hosting:

**Tracking mode** (`trackingMode`, default `hybrid`). Counts on the server and
confirms from the browser. Leave it unless you have a reason.

**Address source** (`ipSource`, default `auto`). If the site is behind a proxy
that is not Cloudflare - a load balancer, Varnish, a WAF - set this explicitly
and list the proxy in `trustedProxies`. Left wrong, every visitor looks like
the proxy and unique visitors collapse to one per day. The Settings screen
shows the address it currently resolves for your own request, which makes this
a ten-second check.

**Write driver** (`writeDriver`, default `auto`). Uses the file spool when
uploads are writable, the database queue when they are not. Only change it if
`info` shows the wrong one.

**Cron** - see [`cron.md`](cron.md). Not required; the plugin counts on hosts
with no scheduled tasks at all. A real cron makes the figures slightly fresher
on a busy site, which is the only thing it changes.

## Country reporting (Pro)

Countries are resolved on your own server from a local database. No lookup
service is ever called, and nothing is downloaded on its own - fetching a
database is your decision and it carries a licence.

Two free ones work: **DB-IP IP to Country Lite** and **MaxMind GeoLite2
Country**. Both give you a `.mmdb` file, and either can be installed without a
terminal from **Analytics → Locations**:

| Route | When to use it |
|---|---|
| Upload a file | The obvious one. The screen prints your host's upload limit; the country databases are well under it. |
| Fetch an address | For a file too large to upload, or a host that would rather download than receive. HTTPS only, and it happens once, when you press the button. |

The same screen replaces or deletes the database later. Country is the finest
thing derived - never a city or a region - and country reporting starts from
the next visit rather than being backdated.

For anybody who does prefer a terminal:

```bash
wp honest-analytics geo install --url=https://example.com/dbip-country-lite.mmdb.gz
wp honest-analytics geo status
```

## Multisite

Network activation installs on every existing site and on every site created
afterwards. Each site has its own tables, settings and reports; there is no
network-wide roll-up screen. The licence is stored once for the network.

```bash
wp honest-analytics drain --network
wp honest-analytics info --url=example.com
```

## Configuration in `wp-config.php`

Anything set here overrides the database and renders disabled in the admin,
labelled "Set in wp-config.php".

```php
define( 'HONEST_ANALYTICS_PRO', true );

define( 'HONEST_ANALYTICS_SETTINGS', [
	'trackingMode'   => 'hybrid',
	'ipSource'       => 'x_forwarded_for',
	'trustedProxies' => [ '10.0.0.0/8' ],
	'honourDnt'      => true,
] );

// 'cache' or 'db', when auto-detection picks wrongly.
define( 'HONEST_ANALYTICS_STORE', 'db' );
```

## Uninstalling

Deactivating keeps everything. Deleting drops the tables - permanently, because
there is no raw data to rebuild them from. See [`uninstall.md`](uninstall.md).
