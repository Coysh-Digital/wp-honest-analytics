# Uninstalling

## Deactivating keeps everything

Deactivating unschedules the cron events and stops collection. Tables,
settings, capabilities and history are untouched. Reactivating resumes exactly
where it stopped.

Somebody switching a plugin off for ten minutes has not asked to lose two years
of history.

## Deleting keeps everything too, by default

`keepDataOnUninstall` is **on** out of the box. Deleting the plugin removes the
plugin; the analytics tables stay where they are, and a later install picks them
straight back up.

That is deliberate. The rollups cannot be rebuilt from anything else - there is
no raw hit data to replay - so destroying them has to be a decision somebody
takes, not the one they get for not reading a checkbox.

The Privacy screen states which way the setting currently stands, rather than
warning about something that may not apply.

## Turning that off

**Settings → Advanced → Keep data on uninstall**, unticked. Deleting the plugin
then runs `uninstall.php`, which:

1. Drops all twenty-six `{$wpdb->prefix}honest_*` tables.
2. Deletes `honest_analytics_settings`, `honest_analytics_db_version`,
   `honest_analytics_licence`, `honest_analytics_last_drain` and
   `honest_analytics_last_gc`.
3. Clears the scheduled events.
4. Removes the three capabilities from every role.
5. Deletes the spool directory from uploads.
6. Deletes the per-user dashboard widget preferences.

On multisite this runs for every site, and the network licence option is
removed once.

**This cannot be undone.** Export first.

## Moving hosts, or reinstalling later

Leave the setting alone and the tables travel with the database. Or take them
explicitly:

```bash
wp db export analytics-backup.sql --tables="$(wp db tables 'wp_honest_*' --format=csv)"
```

Or export the reports themselves:

```bash
wp honest-analytics privacy posture > analytics-posture.txt
wp honest-analytics report pages --range=12mo --limit=1000 --format=csv > pages.csv
```

## Verifying an uninstall

```bash
wp db query "SHOW TABLES LIKE '%honest%'"
wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE 'honest_analytics%'"
wp cron event list --fields=hook | grep honest
ls wp-content/uploads/honest-analytics 2>/dev/null
```

With **Keep data on uninstall** off, all four should be empty. With it on - the
default - the first should still list twenty-six tables, and that is the point.

## Testing uninstall in development

Do not run `wp plugin uninstall` against a checkout. It deletes the plugin
*directory*, and in a development harness that directory is usually a mount of
your source tree. This is not hypothetical - it is why `dev/setup.sh` mounts
the repository read-only and why `dev/uninstall-test.sh` exists:

```bash
cd dev && ./uninstall-test.sh
```

It defines `WP_UNINSTALL_PLUGIN`, includes `uninstall.php` through `wp eval`,
and reports what survived. No files are touched.
