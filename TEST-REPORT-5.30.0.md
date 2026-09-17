# Verification: 5.30.0

Environment: isolated local WordPress 7.1 with SQLite Database Integration 1.8.0,
PHP 8.4.25 and the official Rank Math Free 1.0.278 package. This is not the live
MilesWeb website. External HTTP was disabled; Gemini responses were mocked for
repair/finalizer tests. Rank Math scoring was never mocked.

- 45 integration assertions: real 76/79/80/81/84/85 scores, threshold publication,
  missing and invalid scores, saved-but-unverified 95 replaced by real 79,
  scheduled scoring without an editor, stale 85 after edit, improvement 76 to 84,
  taxonomy/schema/SEO edits, noindex, three attempts, cross-category worker,
  incomplete/skipped posts, duplicate publication, settings/key preservation.
- 11 extended assertions: one existing-writer repair, real improved score,
  image checkpoint reuse, customized metadata, featured ALT invalidation,
  concurrent save filter protection, existing finalizer with images before scoring.
- Separate Rank Math-disabled and Node-unavailable processes: safe Draft and retry.
- Existing updater integration suite: 13 assertions for stable release assets,
  no branch/tag fallback, draft/prerelease rejection and error handling.
- Real browser: generated post #118 had background score 80. Opening Gutenberg
  and Rank Math showed 80/100. Posts -> SEO Details showed 80/100 for the same post.
- All packaged PHP files syntax-checked; Node wrapper syntax-checked; ZIP integrity
  and version consistency checked by the release builder.

No live MilesWeb test, paid Gemini generation, paid image generation, Rank Math
PRO or custom browser scoring-extension validation was performed. Existing
Rank Math SQLite migration errors in the isolated test installation are unrelated
to this plugin; the article metadata/analysis/publication tests ran successfully.

Modified production files: main plugin bootstrap; RankMath worker (new); analyzer
CLI (new); Publish (central gate/freshness); Runner (final sequencing/repair);
SEO (metadata ownership); Utils (global worker/deactivation); Admin (diagnostics).
No API keys or site settings are included in release files.
