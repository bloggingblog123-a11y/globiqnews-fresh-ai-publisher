=== GlobiqNews Fresh AI Publisher ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 5.31.0

AI article publisher with Rank Math 80+ publishing and GitHub plugin updates.

== Description ==
Creates category-based articles, saves Rank Math metadata and original images,
and analyzes final articles using Rank Math Free 1.0.278's actual JavaScript engine.
Uses your HTTPS scoring service, or local Node.js with PHP proc_open. Publishes only with a verified
score of 80 or higher when Auto Publish is enabled. The strict checklist does not
block publishing. Includes settings, draft diagnostics and recovery tools.

GitHub updates use published stable releases from:
https://github.com/bloggingblog123-a11y/globiqnews-fresh-ai-publisher

== Installation ==
Upload the installable ZIP in WordPress and choose Replace current with uploaded.
Enable auto-updates on Installed Plugins to receive future published releases.
Existing settings and API keys remain in WordPress.

== Hosting ==
Shared hosting can use your HTTPS scoring service without local Node.js or proc_open.
Select remote mode, enter its address and secret, enable article-sharing permission,
then save settings. The supplied service matches WordPress 7.1 analysis dependencies
and Rank Math Free 1.0.278. Changed dependencies are refused until verified.
Local mode requires Node.js 18+, PHP proc_open and temporary storage.
Missing service/runtime or unsupported Rank Math version keeps Draft.
Rank Math PRO and custom editor JavaScript scoring extensions are not verified.

== Changelog ==
= 5.31.0 =
Authenticated remote scoring for shared hosting, with a free Render deployment template.
= 5.30.0 =
Actual background analysis, fresh-score publication, bounded retries and one SEO repair.
= 5.29.0 =
Adds GitHub plugin updates and preserves the Rank Math 80+ publishing fixes.

= 5.28.0 =
Fixes existing drafts remaining unpublished despite a saved Rank Math score of 80+.
