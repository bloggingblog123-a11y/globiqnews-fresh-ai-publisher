# GlobiqNews 6.1.0 — originality, titles and factual review

The existing plugin was extended. Images were excluded from this update at the user's request. No production articles were generated, edited or published during validation.

## 1. Architecture and root findings

The previous version already separated research/fact extraction from planning and final writing. RSS descriptions were discovery inputs rather than a direct article-body fallback, and publication was already Draft-only. Its main gaps were the absence of a dedicated source/site headline comparison stage, no explicit source-snippet checks on descriptions/excerpts, and sentence matches that were counted but did not independently fail the lexical check. Manual quality rechecks also did not consistently include current SEO metadata.

The pipeline is now discovery → accessible research/evidence extraction → fact sheet → independent angle/outline/value plan → independent headline generation and comparisons → new article → body/title/description/factual/value review → eligible SEO repair/analysis → Draft → human review → manual WordPress publishing. Failed originality is regenerated from the facts and a new plan, with the existing limit of two regenerations after the initial attempt. An exhausted generation records failure rather than saving its copied candidate body.

## 2. Runtime files modified

- `globiqnews-fresh-ai-publisher.php`: load title engine, release owned title reservations at shutdown, version 6.1.0.
- `includes/class-gnf5-research.php`: collect discovered/fetched headlines and comparison snippets, prefer referenced research links within the source budget, expose structured fact sheet, keep full comparison prose temporary.
- `includes/class-gnf5-sources.php`: extract page descriptions for private originality comparisons.
- `includes/class-gnf5-topics.php`: retain generated titles in existing topic history.
- `includes/class-gnf5-writer.php`: independent headline stage, shared five-attempt title budget per generation invocation, metadata-copy regeneration and explicit rejection of paragraph paraphrases/translation as added value.
- `includes/class-gnf5-quality.php`: title/meta/sentence/fact checks, complete review fingerprint, current article metadata, editorial state and quality logs.
- `includes/class-gnf5-runner.php`: release title reservations, gate text optimization on substantive review, preserve review/checkpoint consistency, omit optional title cosmetics from forced repair.
- `includes/class-gnf5-seo.php`: retain sentiment/power-word/number diagnostics without requiring them or preserving them at the expense of factual neutral wording.
- `includes/class-gnf5-admin.php` and `assets/report.js`: original-content report, source headline/fact-sheet views, explicit title replacement and non-destructive title recheck, stale-report notice.

Release notes, README, readme, changelog and regression fixtures were updated. Queue/settings/image/updater implementation files are unchanged.

## 3. Added files

`includes/class-gnf5-titles.php` implements `GNF5_Titles`. `tools/tests/test-original610.php` and `tools/tests/test-report610.cjs` cover the new behavior. This report documents implementation, tests and limits. Existing pipeline, SEO and security test fixtures were extended.

## 4. Fact extractor

The extractor retains evidence-backed facts, source IDs/URLs, dates, quantities, entities, exact short attributed quotes, conflicts and uncertainties. The new fact-sheet view groups facts by kind and shows primary/supporting sources and estimated multi-source corroboration. Referenced research links are tried earlier so the five-source budget is less likely to be filled by secondary pages first. A link is not automatically certified primary merely because it was fetched. Source independence and primary-source identification remain heuristics/AI-assisted judgments.

## 5–6. Writer context and title engine

The planner, title generator and final writer receive structured fact fields, source IDs, uncertainty/conflict notes and the independent plan. Full source prose, source headlines, source headings and evidence excerpts are excluded from `writer_facts()`. Title retry feedback explains rejection without supplying the rejected source headline. Explicit title-only regeneration can also use the current article to keep the new title aligned with its actual content.

Both H1 and Rank Math SEO title are generated independently. Up to five headline attempts are allowed in one article-generation invocation, shared across originality retries; each provider call retains the existing bounded transport retries. A separate explicit title replacement has its own five-attempt budget. Failure remains visible for manual review. Titles still undergo the article's factual review; novelty alone is insufficient. `Regenerate Title Only` requires administrator confirmation and does not change body, excerpt, slug or images. Concurrent manual changes cancel the replacement.

## 7. Title comparison methods

Case, HTML entities, whitespace and punctuation are normalized. Full normalized equality catches exact/punctuation-only copies. Unique-token Jaccard overlap, with a minimum five-token title and limited launch/launches normalization, flags near matches at default 0.80. The filter `gnf5_title_similarity_threshold` is bounded to 0.75–0.98; it is a quality heuristic, not a Google rule.

Comparisons include collected discovery and fetched-source titles, all current site post titles and SEO titles (paged through the database, including Draft/Published/Trash), retained topic history and live title reservations. Atomic option insertion prevents two workers reserving the same normalized title. Reservations expire and are released on normal/finally/shutdown paths. This is not a web-wide uniqueness search or a multilingual semantic headline detector. Simultaneous differently worded near matches are not a globally serialized semantic guarantee.

## 8. Article originality

Existing eight-/sixteen-word sequences, heading overlap, introduction/conclusion similarity and AI structural comparisons are retained. A copied narrative sentence of at least ten words now independently fails even with low aggregate overlap. Default whole-body eight-word overlap failure threshold is 12%, configurable through `gnf5_originality_overlap_threshold` within 3–25%; long exact sequences/sentences/structural imitation can fail below that threshold. Properly marked, evidence-backed short attributed quotes retain their exemption.

Meta descriptions and excerpts are checked for eight-word source sequences against accessible source prose, page descriptions and temporary discovery/RSS snippets. Missing source comparisons or semantic review cannot be presented as PASS. Structural review explicitly checks paragraph-by-paragraph paraphrase, narrative order, headings, opening and conclusion even when words differ. This semantic part is AI-assisted, not an independent plagiarism service. Names and unavoidable short factual phrases may coincide; every heuristic can have false positives and negatives.

## 9. Added value

The existing 100-point internal rubric remains: source verification 20, background 20, explanation 20, comparison/timeline/data 15, practical implications 15 and additional information 10. AI-selected passages must actually occur in the article and reference retained fact IDs; heavily overlapping passages cannot earn repeated category credit. The review instruction explicitly rejects different wording/translation alone as added value. Insufficient value shows `Added Value Needs Improvement`; it is not a search-engine approval score. No fabricated history, comparisons or padding are added to meet length.

## 10. Factual integrity

Supported claims must reference retained facts and cover headline, SEO title, description, excerpt and substantive body paragraphs. Incomplete review stays UNKNOWN; unsupported reviewed claims now fail. A deterministic numeric guard additionally rejects numbers absent from retained evidence, except an actual HTML list-item count. Long direct quotes must match a verified quote and name its attribution. Source conflicts remain `FACT CONFLICT — MANUAL REVIEW REQUIRED`. Matching a number alone does not prove its meaning; the AI evidence review and human review remain necessary.

## 11. Source isolation and retention

Source extraction and comparison may see raw prose. Writing does not. Full fetched prose/descriptions are removed from permanent research metadata; bounded comparison data uses a one-day transient, while existing research/generation jobs use bounded temporary caches. Short evidence excerpts, source identity/headlines, facts and review excerpts remain in private post metadata for audit. No source article is dumped into the published body. Expired/unavailable comparison material yields UNKNOWN unless successfully refetched. Automatic source links still default OFF; existing saved preferences and category manual-link controls are preserved rather than silently overwritten.

## 12. Images — excluded from this update

`class-gnf5-images.php`, existing image settings/save handlers and media action code were preserved. Image-off tests still prove zero image provider calls and zero attachments; media tests still cover manual image protection. The image implementation and unchanged helper/action methods are compared with 6.0.6 during packaging. No new image protections or image behavior are claimed by this release.

## 13. Rank Math

The existing actual Rank Math Free 1.0.278 analyzer and metadata integration are retained. Local text diagnostics are not written as a Rank Math score. Critical title/body/metadata-originality/factual checks must pass before automatic text repair. Cosmetic title sentiment, power words and numbers remain diagnostics but no longer force rewriting. Relevant factual numbers can still be used. Opening focus keyword, evidence-supported length, metadata, links and paragraph checks remain.

This update does not make Node.js available on MilesWeb Premium. Actual background scoring still requires a compatible runtime or configured service; the WordPress editor remains the alternative. No 80+ score is promised, and Rank Math score does not prove originality or trigger publication.

## 14. Draft-only verification

Every plugin-created/updated article uses the existing Draft-only write wrapper. Tests prove that Rank Math 100 cannot publish a generated Draft and that ordinary administrator publication in WordPress continues to work. No production publication was attempted.

## 15–16. Executed tests and passing results

Disposable WordPress 7.1, PHP 8.4.25, SQLite, Rank Math Free 1.0.278; external source and Gemini responses are controlled fixtures. Tests were run sequentially against the disposable database and restored their settings/posts. The real bundled Rank Math analyzer was used for SEO assertions.

| Suite | Distinct passing checks |
|---|---:|
| Research/writer/originality/base pipeline | 84 |
| New title/originality/factual/metadata/manual-edit suite | 54 |
| Existing non-image SEO | 30 |
| Opening keyword/length/actual Rank Math repair | 33 |
| Media and category generation regression | 36 |
| Category settings persistence | 75 |
| Immediate queue/RSS/retry handling | 47 |
| Security, activation, deactivation and edge cases | 30 |
| Foundation/settings/source validation | 28 |
| JavaScript queue/error state | 8 |
| JavaScript title action/dirty editor/confirmation | 4 |
| **Distinct total (shared suites counted once)** | **429** |

Specific new tests include exact/punctuation/near-copy headlines; a distinct framing; copied SEO title with different H1; existing Draft/Trash and live title reservations; copied first title followed by a successful new title; five failed attempts; one copied sentence in a long otherwise different body; RSS snippet copied into meta; source prose in excerpt; structural imitation; missing structural review; repeated added-value credit; invented number despite an approving mock response; factual conflict; source-prose isolation; title-only replacement preserving body/slug/images; factual rejection; concurrent manual edit; initial plus two failed body attempts; and no SEO provider call for a failed-originality Draft. Security tests include required title confirmation, authentication and nonce checks.

Queue tests exercise concurrency 1 and 2, exhausted/failed/blocked categories, immediate handoff and independent six-hour source retries. Settings tests exercise persisted category changes and stale-form protections. PHP syntax, JavaScript syntax, archive integrity, version consistency, image-preservation comparisons and a secret-pattern scan were also performed for the release.

## 17. Tests not performed here

No production MilesWeb requests/database, real paid Gemini quality evaluation, live GDELT/RSS reliability test, new browser end-to-end editor test, Rank Math PRO/custom editor extension validation, cross-language originality benchmark, large-corpus title performance benchmark or simultaneous production MySQL stress test was performed. Fixture tests validate control flow and failure handling, not real-world AI truthfulness or writing quality. Queue live-loopback evidence from 6.0.4 remains historical and is not counted as newly executed browser validation.

## 18. Known limitations

Factual/originality checks reduce obvious failure paths but cannot guarantee original or correct prose. Headlines are compared to available inputs and site/history records, not the entire web. Short or heavily rephrased copies can evade lexical checks; semantic checks depend on the model. Metadata checks require available comparison text; expired discovery snippets cannot be reconstructed from a different current feed. Refetched source content can change. Source blocks, insufficient evidence, exhausted budgets or provider failures require manual review. Five-source research and all-site title scans have practical coverage/performance limits. Human approval is still required for every Draft.

## 19. Migration and preservation

No database schema migration, destructive cleanup, settings reset or bulk rewriting of existing articles is performed. New report fields and title history are populated as work runs. Older drafts with structured research can be rechecked; drafts lacking it need explicit regeneration. Old reports are not relabeled as new passes. Saving a version-2 preflight review updates the owned checkpoint so it is not mistaken for a human content edit. Existing authors, limits, RSS/GDELT/URL paths, queue/retries, manual links, images, recovery and GitHub updater are retained. Existing deactivation/retention behavior is unchanged; no new destructive uninstall routine was added.

## 20. No approval claims

The plugin does not claim Google/AdSense approval, copyright clearance, web-wide uniqueness, plagiarism-proof output or guaranteed rankings. It does not use humanizing/obfuscation techniques to disguise copied content. All scores and PASS labels describe the stated available comparisons and evidence review, followed by human review.

## Using this update

Install through the existing GitHub updater (Plugins → Check for updates → Update now) or upload the installable ZIP and replace the current version. Keep the plugin installed to retain settings. New generation uses the new stages. For an existing Draft, save editor changes, open **GlobiqNews Original Content Report**, then use **Recheck Originality, Value & Facts** or **Recheck Title Originality**. **Regenerate Title Only** replaces only the titles after confirmation and checks. Read warnings and manually publish only after review.
