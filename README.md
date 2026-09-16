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

Completed generated drafts can publish when their real saved Rank Math SEO score is
80 or higher. The strict SEO checklist is not a publishing requirement. Missing or
lower scores keep articles as drafts. Rank Math calculates and saves its score in
the editor. Use the plugin's **Draft Auto Publish Status** panel to inspect saved scores
and **Check & Publish 80+ Drafts Now** to recheck eligible drafts.

## Third-party updater

Includes [Plugin Update Checker 5.7](https://github.com/YahnisElsts/plugin-update-checker/releases/tag/v5.7)
from commit `275a96a` under its included MIT license. GitHub releases and the WordPress
update system are used directly. The repository must stay public for token-free updates.
