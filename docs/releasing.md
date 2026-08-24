# Releasing

One source tree, two published repositories, and a script that keeps them in
line.

```
Coysh-Digital/wp-honest-analytics-pro    private   the source, and the paid edition
        │
        │  bin/publish-lite.sh
        ▼
Coysh-Digital/wp-honest-analytics        public    the free edition, exactly as it installs
        │
        │  GitHub Action, on tag
        ▼
wordpress.org/plugins/honest-analytics
```

## Why it is arranged this way

The free edition is **generated, never edited**. The public repository holds
what a person installs and nothing else - no tests, no development harness, no
build scripts - and it is rebuilt from the private one on every release.

That is what stops the two drifting apart. There is no second copy of the code
to keep in step, because there is no second copy: `bin/pro-manifest.txt` says
which paths are Pro-only, the build removes them, and a guard refuses to
package a free build that still reaches for something it removed.

## Cutting a release

1. **Set the version** in three places, which must agree or the workflow fails:

   ```
   honest-analytics.php    * Version:  0.2.0   and   const VERSION = '0.2.0'
   readme.txt              Stable tag: 0.2.0
   CHANGELOG.md            ## [0.2.0] - <date>
   ```

2. **Check it.**

   ```bash
   composer cs && composer stan && composer test:unit && composer budgets
   cd dev && ./integration.sh && cd ..
   composer build
   ```

3. **Tag the private repository.** The Action runs the checks again, builds both
   editions and attaches the paid zip to a release.

   ```bash
   git tag -a v0.2.0 -m "Honest Analytics 0.2.0"
   git push origin main --tags
   ```

4. **Publish the free edition.** Deliberately a separate command, because
   pushing to a public repository should be something somebody decides to do
   rather than something a tag does on their behalf.

   ```bash
   bash bin/publish-lite.sh              # rebuild and show the diff
   bash bin/publish-lite.sh --push --tag # when it looks right
   ```

   Tagging the public repository triggers its own Action, which attaches the
   free zip to a public release and - once the plugin directory credentials are
   in place - deploys to wordpress.org.

   Leave `--tag` off to push a rebuild of a version that is already out: the
   commit subject then says "rebuilt", the tag stays where it is, and the
   release keeps the zip it was published with. Use it when something outside
   the plugin changed - a workflow file, a document - and the code did not.

## Where things stand

Both repositories exist and 0.8.3 is released on each: the paid zip on the
private one, the free zip on the public one. 0.8.4 is the first release
prepared for the directory. The plugin directory submission has not been made
yet, which is the next step below.

## The plugin directory

The first submission is by hand, at
<https://wordpress.org/plugins/developers/add/>, with the zip from
`build/honest-analytics-0.8.4.zip`. Review takes one to ten days, sometimes
longer.

Before uploading, paste `readme.txt` into the
[readme validator](https://wordpress.org/plugins/developers/readme-validator/),
and install the zip on a clean site with `WP_DEBUG` and `WP_DEBUG_LOG` on and
click every screen. Not testing with `WP_DEBUG` is on the directory's own list
of common reasons for refusing a plugin.

The slug is derived from the Plugin Name header, so it will be
`honest-analytics`. It is permanent once approved, and changeable once from the
submission page before review starts. Only one plugin may be in the queue at a
time. If the reviewers find something, reply on the same email thread rather
than submitting again.

Once accepted, add to the **public** repository:

| | |
|---|---|
| Secret `SVN_USERNAME` | the wordpress.org account |
| Secret `SVN_PASSWORD` | its password |
| Variable `DEPLOY_TO_WORDPRESS_ORG` | `true` |

After that every tag deploys itself. The banner and icon go in
`.wordpress-org/`; see the README there for the sizes.

`Stable tag` in `readme.txt` is what the directory actually serves. A release
whose stable tag was not updated is a release nobody receives.

## Versioning

Semantic versioning. The schema has its own number in `Schema::VERSION`, which
moves independently and only when the tables change - a plugin update with no
schema change should not migrate anything.

The paid edition updates itself through the licence layer rather than through
the directory. That path is inert until a commerce provider is chosen; see
[`editions.md`](editions.md).
