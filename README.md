# GlobiqNews Fresh AI Publisher

WordPress article publisher with Rank Math 80+ auto-publishing and GitHub plugin updates.

## Install once

Upload the **installable plugin ZIP** through WordPress **Plugins â†’ Add New â†’ Upload Plugin**.
Choose **Replace current with uploaded** if the plugin is already installed.
The folder and database option names stay the same; existing settings and API keys stay in WordPress.

On **Plugins â†’ Installed Plugins**, find this plugin and select **Enable auto-updates**.
You can also use **Check for updates** and **Update now**. WordPress performs automatic
updates on its own schedule; publishing a release does not cause an instant update.

Only published stable GitHub releases with an attached asset named exactly
`globiqnews-fresh-ai-publisher.zip` are offered. Branch commits, bare tags, draft
releases, prereleases and releases without that ZIP are not installed.

## Publish the first GitHub release

1. Put these repository files on the `main` branch.
2. Open **Actions â†’ Publish plugin update â†’ Run workflow â†’ Run workflow**.
3. Wait for the workflow to finish. **Releases** will contain `v5.29.0` and the installable ZIP.

The included workflow uses GitHub's repository-scoped token. No personal access token
or WordPress/API credentials need to be added to this public repository.

## Publish a future update

1. Modify the plugin code.
2. Increase both the `Version:` header and `GNF5_VERSION` in the main PHP file.
3. Change `Stable tag:` in `readme.txt` to the same version.
4. Describe the change in `RELEASE-NOTES.md`, then commit the changes.
5. Run the **Publish plugin update** workflow. It packages the plugin and publishes the release.

Alternatively, pushing a matching version tag (for example `v5.30.0`) runs the same workflow.
The build rejects mismatched versions. Already released versions must not be reused.

Automatic updates install newly published versions; they do not generate code changes.
Someone still needs to make and publish each release.

## Local build

Run `python tools/build_release.py`. The output is `dist/globiqnews-fresh-ai-publisher.zip`
plus `dist/SHA256SUMS.txt`. Build/workflow files are excluded from the installable ZIP.

## Article auto-publishing

Version 5.30.0 executes the installed Rank Math Free **1.0.278** analyzer using a
local Node.js process. It does not implement substitute scores or call the
save-only `updateSeoScore` endpoint to invent a result. The WordPress dependencies
and analyzer are loaded from the installation. The analyzer version and SHA-256
are checked before use.

Final article data is committed before analysis. The actual result is saved to
`rank_math_seo_score`, read back, and bound to a fingerprint of the inputs and
engine. Only the central publish gate may publish, with Auto Publish enabled and
a fresh numeric score **>=80**. Failed, missing, noindex, invalid or stale scores
stay Draft. Scoring retries at most 3 times; an unchanged generated article may
use the existing writer for one SEO repair before re-analysis. Metadata customized
by a user is preserved. Runtime failure does not regenerate the article.

### Hosting setup

Ask MilesWeb support: “Does my account allow Node.js 18 or newer to run from PHP
proc_open? Please provide the absolute Node executable path.” The provider name
alone does not confirm that the account supports this runtime.

Common system, CloudLinux and cPanel Node paths are detected automatically. For
another path add `define('GNF5_NODE_BINARY', '/absolute/path/to/node');` to
wp-config.php. Use the actual path supplied by your host. Check the plugin's
Analyzer compatibility message and use **Analyze & Publish 80+ Drafts Now**.
Keep WP-Cron working; delayed tasks depend on traffic or a host cron service.

Validated with the default Free 1.0.278 engine, WordPress 7.1 and PHP 8.4.25.
Rank Math PRO and other analyzer versions are refused until verified. Arbitrary
third-party JavaScript scoring filters and page-builder-specific editor content
are outside the tested compatibility scope. The supported workflow is the
plugin's standard post/Gutenberg output. Do not enable this release for such
customizations without a comparison against the actual editor.

### Verification

See `TEST-REPORT-5.30.0.md`. Genuine 79/80/81 boundary tests and stale-score tests
run against actual WordPress and the installed Rank Math engine. The real editor
and Posts list both showed 80/100 for the same background-scored fixture.
Live MilesWeb runtime verification is still required before production rollout.

## Third-party updater

Includes [Plugin Update Checker 5.7](https://github.com/YahnisElsts/plugin-update-checker/releases/tag/v5.7)
from commit `275a96a` under its included MIT license. GitHub releases and the WordPress
update system are used directly. The repository must stay public for token-free updates.
