# GlobiqNews Fresh AI Publisher — 6.0.0

Discover topics, collect evidence, plan independent articles, and save original **Drafts for human review**. Version 6 replaces automatic article publication with manual publishing, including when Rank Math shows 80 or 100. Normal WordPress publishing remains available.

## Install this update

Use `globiqnews-fresh-ai-publisher.zip` in WordPress → Plugins → Add Plugin → Upload Plugin. Select **Replace current with uploaded**. Do not delete the old plugin first. Test this major workflow change on a staging copy before updating production.

Open **GlobiqNews Fresh AI** in the WordPress sidebar. Existing API keys, category sources, instructions and saved image choices remain. Blank key fields mean “keep the saved key.” Choose an author for each category before running it.

1. Save your Gemini settings and use Test Gemini Connection. The configured model must be available to your provider account.
2. For each category choose any combination of GDELT, RSS/Atom and Source URLs. GDELT needs topic keywords; it does not require an RSS URL. A Manual Article URL works without automatic discovery settings.
3. Set the number of successful new Drafts per run, timing and author. Save the category. Failures do not consume successful-Draft slots.
4. Choose global/per-category images. Images default OFF for a new installation; upgrades preserve the previous explicit choice. OFF makes no image-generation calls and keeps your own images. ON permits at most one generated featured image and one different inline image.
5. Run one category or research a manual URL. Review the saved Draft through **View Research / Quality** or the editor’s GlobiqNews report. Check sources, facts, originality warnings, value, images and SEO. Publish manually from WordPress when ready.

## Research and quality

Research can read accessible source text. The final writer and SEO optimizer receive structured facts with evidence IDs, rather than full source articles. A protected Gemini system instruction requires original structure, supported facts and Draft-only behavior. Articles may be shorter than the preferred 1000–1200 words; the plugin does not force filler, power words, dates, FAQ or keyword density.

The private report separates lexical source comparison, AI structural/factual review, and an internal Added Value Score (default target 70). These are review aids, not plagiarism-proof or independent human verification. Missing evidence is UNKNOWN / NOT CHECKED. Conflicting facts require manual review. Failed originality regenerates from a new plan at most twice.

Source fetching is bounded and uses WordPress safe HTTP. Access restrictions, CAPTCHA, paywall/login indicators and connection errors are skipped for six hours without bypass attempts. Topic history is bounded to 1,000 entries and its retention setting; source comparison text expires after one day. Private fact evidence remains attached to the Draft for editorial review.

## Rank Math and hosting

Rank Math keeps ownership of canonical URLs, schema and sitemaps. The plugin synchronizes supported metadata and can run the actual verified **Rank Math Free 1.0.278** analyzer locally with Node.js 18+ / PHP `proc_open`, or through your optional authenticated HTTPS scoring service. Up to three quality-checked SEO optimizations can improve unchanged generated text. **Scores never publish articles.**

Node.js/Render is optional for creating Drafts. Without a compatible background analyzer, the score remains unavailable until calculated with Rank Math in the WordPress editor. You do not need to purchase a hosting upgrade to use the Draft workflow. The supplied remote runtime is pinned to WordPress 7.1 dependency hashes; mismatches are refused. See [optional service setup](scoring-service/README.md).

Background work never overwrites detected human edits. Explicit text regeneration asks for confirmation, replaces the text and preserves manual image markup and thumbnail ownership. Review image placement/captions after an explicit regeneration. Ordinary editor changes and manual publishing work normally.

## GitHub updates

The existing updater accepts only published stable releases with the asset named exactly `globiqnews-fresh-ai-publisher.zip`. Branch commits, draft pull requests and prereleases do not trigger website updates. This 6.0.0 delivery is a review candidate; publishing a stable release is a separate action.

For future approved releases, keep the plugin header, constant and readme Stable tag aligned, run the tests, and use **Actions → Publish plugin update**. Enabling WordPress plugin auto-updates controls code updates, not article publication. Settings remain in WordPress.

## Validation

See [6.0.0 delivery and test report](TEST-REPORT-6.0.0.md) and [specification coverage](SPEC-COVERAGE-6.0.0.md). Live Gemini/OpenAI responses, arbitrary publisher layouts, MilesWeb networking, Render deployment and production cron timing have not been verified for this candidate. No production website was changed.
