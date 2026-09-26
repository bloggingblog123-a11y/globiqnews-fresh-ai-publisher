# GlobiqNews 6.1.1 — actionable non-image SEO repair

## Problem and code findings

The reported article had low focus-keyword density, a reused broad focus keyword, no number in its SEO title, fewer than 600 words and no internal links. Its installed version/creation date and production research record were not available, so the exact reason each live repair stopped is not claimed here. Code inspection found these concrete limitations:

1. Density feedback gave a percentage range without a target occurrence count. An improvement below the passing threshold could be discarded as no progress. Growth needed a 60-word step, so smaller useful steps were also rejected.
2. All keyword changes were prohibited during repair. Keyword reuse was reported in the checklist but never added to repair feedback. The lookup examined only the first 100 substring candidates.
3. Version 6.1.0 deliberately made title numbers optional following the earlier neutral-title specification. This release follows the user's newer request to attempt a factual numbered title, while still refusing invented numbers.
4. Explicit improvement did not refresh expired private source comparisons. A stored UNKNOWN review could continue blocking an otherwise unchanged old Draft.
5. Internal-link eligibility used target headline/focus-keyword terms, so a relevant published article with a different headline and no focus-keyword metadata could be missed. There was no specific explanation distinguishing linking OFF from no relevant target.
6. Adjacent HTML block tags without spaces could merge words during local word/keyword counting.

## Changes

The writer receives measured current word count, exact-phrase occurrences/density, a near-1.5% target and an occurrence goal recalculated for the suggested final length. For example, 650 words suggests about ten naturally distributed occurrences. The local passing band is 1.3–1.7%; the genuine Rank Math analyzer still calculates its own score and combinations separately. Partial density improvements of at least 0.1 percentage points toward 1.5% can proceed after factual/originality review. Supported length growth of 40 words can proceed toward 600. A repair cannot turn a low/normal density into excessive density or regress already-passing substantive checks.

Reused broad keywords can be extended with an unused, meaningful qualifier already present in the article, retaining the original phrase and story. The replacement must be at most eight words and appear correctly in the title, description, slug, opening, heading and body. Unrelated topic changes, still-duplicate phrases and inconsistent placement are rejected. New generation receives recent used keywords to avoid, and saved-Draft repair checks the full candidate set with paginated lookup. The original phrase is retained privately for relevant internal-link matching, not as a second Rank Math focus keyword.

Title-number repair now requests a useful number from verified facts or a truthful count of distinct discussed items. No arbitrary year, invented statistic or artificial list is inserted. Sentiment and power words remain optional. The provider can return `seo_unresolved` explanations, which are displayed in the editor when a target cannot be supported. A unchanged/unhelpful rewrite stops instead of spending the remaining budget on repeats.

The explicit **Improve SEO & Links** action refreshes source comparisons and quality review, checks that the Draft is still unchanged, then attempts bounded repairs. A one-time policy 611 budget allows up to three improvements on an unchanged older Draft whose previous budget was exhausted; repeated clicks do not reset that budget. Human edits during source refresh/AI review cancel writes. A substantive factual/originality failure still prevents automatic text rewriting.

Internal links search relevant published titles, focus keywords and bounded body/excerpt context, using up to six search terms. A factual keyword refinement retains the broader subject for matching older related articles. Only public published, non-password-protected targets are inserted; private, Draft and unrelated targets remain excluded. Linking OFF is respected. The checklist records whether linking is disabled, DOM support is missing, no keyword exists, no relevant target was found, or valid links are present.

Word and phrase counters now treat block boundaries as spaces, even in compact AI HTML. Images and their controls/actions are unchanged. Every plugin write remains Draft-only. Nothing in this update edits existing production posts on installation or publishes articles.

## Files

Runtime changes: `includes/class-gnf5-seo.php`, `class-gnf5-writer.php`, `class-gnf5-runner.php`, `class-gnf5-titles.php`, `class-gnf5-utils.php` (word-count helper only), and `class-gnf5-admin.php` (unresolved reason display). Plugin header/readme/version, release notes and changelog are updated. New regression suite: `tools/tests/test-seo611.php`. Existing SEO/title regression expectations are updated for the explicitly requested factual-number and reused-keyword behavior.

## Tests actually executed

Disposable WordPress 7.1, PHP 8.4.25, SQLite, Rank Math Free 1.0.278. Research and Gemini responses are controlled fixtures. The real Rank Math JavaScript analyzer is run locally; its tests are not substituted with fabricated results.

- New SEO 6.1.1 suite: **41 checks**, including the reported 477-word/three-occurrence scenario; compact HTML counts; measured target of ten occurrences at 650 words; partial density progress; overshoot rejection; actual saved expansion and numbered title; reused-keyword refinement across every placement; body-based internal-link matching; private-target exclusion; linking OFF diagnostics; persistent three-attempt cap; expired comparisons; concurrent human edits; unsupported expansion with an unresolved-number explanation; and an exact keyword match beyond 100 substring candidates.
- **Real Rank Math passes `keywordDensity`, `titleHasNumber`, `keywordNotUsed`, and `linksHasInternal` for the repaired fixture. Its content-length test no longer reports under 600 words.** The article remains Draft.
- Existing distinct checks: pipeline 84, title/originality 54, non-image SEO 30, SEO 6.0.6 33, media/queue generation 36, settings persistence 75, immediate queue/RSS 47, security 30, foundation 28, JavaScript queue/error 8, title-action JavaScript 4.
- **470 distinct automated assertions passed**, counting shared suites once. PHP/JavaScript syntax, archive integrity/version checks, image-code preservation and secret-pattern scan also passed.

## Limits and deployment

No production MilesWeb site, live provider quality benchmark or large-site performance/load test was run. Fixture-generated expansion tests validate the control flow and saved output, not real-world AI writing quality. A 600-word article, relevant internal link, unique natural keyword or numbered title cannot be guaranteed when the available facts/site content do not support it. The repair leaves an explanation and preserves the Draft rather than padding, linking unrelated posts or inventing claims. Existing manual edits are protected; administrators can use the checklist to edit those posts themselves.

Background scoring requirements are unchanged: this release does not add Node.js to the hosting plan. Use Rank Math in the editor or a compatible configured analyzer. No local checklist is written as a Rank Math score and no overall 80+/100 score is guaranteed. Rank Math's own guidance describes natural keyword use around 1–1.5% as sufficient; its displayed density can differ from an exact-phrase count because of combinations and the final rendered content: [Rank Math post tests](https://rankmath.com/kb/score-100-in-tests/).

Install v6.1.1 using **Plugins → Check for updates → Update now**, keeping the existing plugin/settings. For each existing unchanged generated Draft, save editor changes, then use **GlobiqNews Original Content Report → Improve SEO & Links**. Refresh/reopen the editor after it completes and inspect the actual Rank Math results and any unresolved reason. New generated Drafts use the updated repair policy automatically when quality checks permit it. Publishing remains a manual human decision.
