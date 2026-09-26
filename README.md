# GlobiqNews Fresh AI Publisher — 6.0.6

6.0.6 improves non-image SEO repair for opening keyword placement, evidence-supported length and factual title numbers, without regressing passing checks. Images and Draft-only behavior are unchanged. See [validation](TEST-REPORT-6.0.6.md).

6.0.5 protects saved settings from older category/full-page forms, verifies committed category values, rejects incomplete saves and shows category-local feedback. Reload the settings page after updating. See [persistence findings and tests](TEST-REPORT-6.0.5.md).

6.0.4 adds a persistent background category queue with immediate worker handoff and configurable concurrency (1–2). Run Selected/Run All save category fields before queueing. Empty RSS stays empty; HTML Source URLs no longer silently import advertised RSS. All articles remain Draft. See [root causes, all 14 findings and validation](TEST-REPORT-6.0.4.md).

6.0.3 fixes overlapping settings saves and adds saved Manual External Links to the category source-test button. If another save is active, wait for its response and retry the same Save button. See [validation and limitations](TEST-REPORT-6.0.3.md).

6.0.2 adds independent category **Save Image Settings** and **Save External Link Settings** controls. Automatic research/source links default OFF; manual external links also default OFF and empty. General saves preserve the two independent sections. See [validation and limitations](TEST-REPORT-6.0.2.md).

In each category, choose image mode, featured/inline preferences and WebP (or optimized JPEG), then press **Save Image Settings**. Configure optional manual link rows and a maximum of 0–3, then press **Save External Link Settings**. A descriptive anchor/purpose and matching URL subject help conservative relevance matching; unclear or inaccessible links are skipped. Preferred links are tried first, never forced. No appended external-link list is created. The global research-link switch is in section 3; use a global Save Settings button for it. Internal links and private evidence research continue when external links are disabled.

These controls affect new composition and explicit improvement/regeneration of unchanged generated Drafts. They do not mass-edit existing posts or remove manually uploaded media. Existing generated image files can be reused even if the new format preference changes; the selected format applies to new files.

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
