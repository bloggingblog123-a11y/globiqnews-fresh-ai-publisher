# GlobiqNews Fresh AI Publisher

WordPress article publisher with Rank Math 80+ auto-publishing and GitHub plugin updates.

## Install once

Upload the **installable plugin ZIP** through WordPress **Plugins → Add New → Upload Plugin**.
Choose **Replace current with uploaded** if the plugin is already installed.
The folder and database option names stay the same; existing settings and API keys stay in WordPress.

On **Plugins → Installed Plugins**, find this plugin and select **Enable auto-updates**.
You can also use **Check for updates** and **Update now**. WordPress performs automatic
updates on its own schedule; publishing a release does not cause an instant update.

Only published stable GitHub releases with an attached asset named exactly
`globiqnews-fresh-ai-publisher.zip` are offered. Branch commits, bare tags, draft
releases, prereleases and releases without that ZIP are not installed.

## Publish the first GitHub release

1. Put these repository files on the `main` branch.
2. Open **Actions → Publish plugin update → Run workflow → Run workflow**.
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

Version 5.31.0 uses Rank Math Free **1.0.278**'s actual analyzer. Choose either a local
Node.js process or your authenticated HTTPS scoring service. Shared hosting such as
MilesWeb Premium can use HTTPS mode without Node.js or PHP proc_open on WordPress.
No alternative scoring rules or invented score numbers are used.

Final article data is committed before analysis. The real result is saved to
`rank_math_seo_score`, read back and bound to the final inputs and engine. With Auto
Publish enabled, only a fresh valid score **>=80** passes the central publishing
gate. Missing, invalid, stale, noindex, failed or lower scores stay Draft. Scoring
retries at most three times. One bounded SEO repair uses the existing writer and
reuses images; customized Rank Math metadata is preserved. Scoring failures do not
regenerate articles.

### Shared-hosting setup

Follow [scoring-service setup](scoring-service/README.md). Select HTTPS mode, enter
the address and shared secret, enable article-sharing permission, then save settings.
The supplied service has been verified with WordPress **7.1** analysis dependencies.
Different dependency hashes are refused; verify the website's version before use.
Free Render instances have startup delays and usage limits. Failed requests leave
Draft and retry; this is a testing option, not guaranteed production availability.

### Local Node.js setup

Local mode needs Node.js 18+, PHP proc_open and temporary storage. Common system,
CloudLinux and cPanel Node paths are detected. Another supported path can be set
using `define('GNF5_NODE_BINARY', '/absolute/path/to/node');` in wp-config.php.
MilesWeb Premium blocks this mode; use HTTPS mode for that plan.

### Verification

See [5.31.0 test report](TEST-REPORT-5.31.0.md). Genuine boundary, stale-score,
repair, HTTP authentication and failure tests use the real analyzer. The prior
local test also compared the actual Gutenberg editor and Posts list at 80/100.
Rank Math PRO and custom JavaScript scoring filters remain outside the verified
scope. Live Render and MilesWeb testing is still required before a stable rollout.
Keep WP-Cron working; delayed jobs depend on traffic or a host cron service.

## Third-party updater

Includes [Plugin Update Checker 5.7](https://github.com/YahnisElsts/plugin-update-checker/releases/tag/v5.7)
from commit `275a96a` under its included MIT license. GitHub releases and the WordPress
update system are used directly. The repository must stay public for token-free updates.
