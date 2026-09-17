=== GlobiqNews Fresh AI Publisher ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 5.30.0

AI article publisher with Rank Math 80+ publishing and GitHub plugin updates.

== Description ==
Creates category-based articles, saves Rank Math metadata and original images,
and analyzes final articles using Rank Math Free 1.0.278's actual JavaScript engine.
Requires Node.js 18+ and PHP proc_open on the host. Publishes only with a verified
score of 80 or higher when Auto Publish is enabled. The strict checklist does not
block publishing. Includes settings, draft diagnostics and recovery tools.

GitHub updates use published stable releases from:
https://github.com/bloggingblog123-a11y/globiqnews-fresh-ai-publisher

== Installation ==
Upload the installable ZIP in WordPress and choose Replace current with uploaded.
Enable auto-updates on Installed Plugins to receive future published releases.
Existing settings and API keys remain in WordPress.

== Hosting ==
Background analysis requires Node.js 18+, PHP proc_open and temporary storage.
Set GNF5_NODE_BINARY to an absolute executable path in wp-config.php if automatic
detection fails. Missing runtime or unsupported Rank Math version keeps Draft.
Rank Math PRO and custom editor JavaScript scoring extensions are not verified.

== Changelog ==
= 5.30.0 =
Actual background analysis, fresh-score publication, bounded retries and one SEO repair.
= 5.29.0 =
Adds GitHub plugin updates and preserves the Rank Math 80+ publishing fixes.

= 5.28.0 =
Fixes existing drafts remaining unpublished despite a saved Rank Math score of 80+.
