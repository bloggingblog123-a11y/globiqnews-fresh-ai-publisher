# 6.0.0 delivery and test report

Date: 21 September 2026. Status: **review candidate, not deployed to production or published as a stable release**.

## 1. Version and behavior

Version 6.0.0 updates the existing architecture. Every newly generated article, recovery write and SEO improvement remains a WordPress Draft. No Rank Math score, including 100, causes publication. Native manual publishing remains available, and ordinary posts are excluded from plugin actions.

## 2. What changed

Discovery now supports optional GDELT alongside existing RSS/Atom, Source URLs and Manual URLs. Research gathers accessible sources, extracts facts with exact evidence, proposes independent structure and useful reader value, then writes original text. Full source prose does not enter the final writer/SEO optimization prompts. Lexical and AI review results are private and explicitly distinguish PASS, WARNING, FAIL and UNKNOWN/NOT CHECKED. Images are optional, with manual media protected. The actual Rank Math engine remains an optimization tool. Existing queue, updater, category data and recovery mechanisms are extended.

## 3–4. Files changed and added

Compared with the 5.31.0 candidate; the PR also contains the earlier real-analyzer and remote-service implementation relative to stable 5.29.0. Full file inventory is in CHANGE-INVENTORY-6.0.0.json.

Modified:
- CHANGELOG.txt
- README.md
- RELEASE-NOTES.md
- assets/admin.js
- globiqnews-fresh-ai-publisher.php
- includes/class-gnf5-admin.php
- includes/class-gnf5-images.php
- includes/class-gnf5-publish.php
- includes/class-gnf5-rankmath.php
- includes/class-gnf5-runner.php
- includes/class-gnf5-seo.php
- includes/class-gnf5-sources.php
- includes/class-gnf5-utils.php
- includes/class-gnf5-writer.php
- readme.txt
- scoring-service/README.md
- tools/tests/README.md

Added:
- assets/report.js
- includes/class-gnf5-quality.php
- includes/class-gnf5-research.php
- includes/class-gnf5-topics.php
- tools/tests/bootstrap600.php
- tools/tests/test-master-foundation.php
- tools/tests/test-master-media-queue.php
- tools/tests/test-master-pipeline.php
- tools/tests/test-master-security.php
- TEST-REPORT-6.0.0.md
- SPEC-COVERAGE-6.0.0.md
- CHANGE-INVENTORY-6.0.0.json

No vendor dependency or GitHub updater implementation was replaced.

## 5–7. Migration, settings and retired options

The once-only migration records `gnf5_migration_version=6.0.0`; its lock expires after five minutes if interrupted. It merges missing defaults without replacing existing category rows, API keys, custom instructions, explicit image choices or queued work. It archives prior publication settings in `gnf5_legacy_publication_preferences`. Existing auto_publish_enabled/auto_publish_recovered and success-status compatibility fields are forced to Draft/disabled and are absent from the publishing UI.

New global preferences: added_value_target (70), originality_retries (2), history_days (90), debug_enabled (OFF). New categories: image_mode (global/on/off), gdelt_enabled, keywords, language, source country, window, result count, scan/cache interval, min_sources, max_candidates, opportunity_threshold (60). Image generation is OFF for a fresh install; upgrade does not turn an existing choice OFF. Category author has no fallback. Blocked-source delay is fixed at six hours.

Data uses existing WordPress options/transients/post metadata: bounded topic history and run reservations; structured research and evidence; quality report, article plan, opportunity score and content/ownership fingerprints. No custom database table. Topic history has a maximum of 1,000 entries and configurable time retention applied when read/written; private comparison text expires in one day and in-progress research/writer caches in six hours. Source URL provenance is retained with research/topic entries; blocked records expire after six hours. No permanent raw HTML copies. Log is limited to 250 redacted records, with filter/export/clear controls. Deactivation retains posts, images, settings and queued selections; no uninstall data purge was introduced.

## 8–9. Tests actually executed

**175 WordPress assertions passed**, using PHP 8.4.25, WordPress 7.1 with SQLite integration, Rank Math Free 1.0.278 and the actual Node analyzer. The checked-in portable test copies were rerun successfully against the disposable local site.

| Suite | Assertions | Evidence |
| --- | ---: | --- |
| Foundation | 28 | New-install defaults, migration/idempotence/secrets, Draft write guard, human publishing of generated and ordinary posts, human-edit protection, actual scores 76/79/80/81/84/85, lock and URL safety |
| Pipeline + media/queue | 120 | Includes 84 pipeline checks; all eight GDELT/RSS/URL combinations, standalone manual URL, fact-only protected prompts, no fake checks, originality regeneration, real WebP files, image OFF zero requests, partial retries, manual images, three SEO optimizations, sequential bulk queue, source failures and three-Draft batch cap |
| Security/edges | 27 | Unauthorized/invalid nonce/malformed IDs, unrelated posts and attachments, regeneration confirmation, human-published post exclusion, stale migration, redaction/debug, numeric/quote evidence, truthful source count, connection retry, activation/deactivation preservation and argument-aware cron cleanup |

**Six scoring-service tests passed:** real loopback HTTP analyzer results 76/79/80/81/84/85, authentication, replay/expiry/dependency mismatch/executable-path rejection, single-worker concurrency, health and missing keyword. No score was substituted for analyzer output.

All **65 PHP files** passed syntax checks; all **three asset JS/CJS files** passed Node syntax checks. Installable and source ZIP integrity, stable root folder and matching version checks were performed by the delivery build. The current source-package test bootstrap refuses web execution and non-local test sites.

Browser checks: settings render with the new category/manual controls and hidden saved keys; private report renders with honest NOT CHECKED states for a legacy article; Recheck Images completed through real admin AJAX and explicitly left the article unpublished. The local preview helper was removed and server stopped afterward.

External source, Gemini and OpenAI HTTP responses in WordPress pipeline tests are **controlled fixtures**, not production provider calls. Built-in images are actual encoded WebP files; paid-provider failure/retry responses are mocked. The original lexical comparison and WordPress parser run normally on fixture sources. AI factual/structural review accuracy cannot be proven by mocked model responses.

## 10–11. Unperformed tests and known limits

- No live MilesWeb, Render deployment, production HTTPS/cron, real API quota/cost or live Gemini/OpenAI generation was tested. No production credentials were used. One local WordPress core bootstrap/heartbeat request timed out at its 30-second PHP limit during concurrent local testing; subsequent requests and plugin report action succeeded. This is not a production load test or a promise that HTTP 500 is impossible.
- GDELT request construction, cache and failure isolation are fixture-tested; live endpoint availability/results are unverified. Publisher HTML structures, JavaScript-only pages and access policies vary. No anti-bot/login/paywall bypass exists. Research examines up to five sources from ten candidates, bounded by the shared source-request budget; it is not exhaustive web research.
- Source independence is a publisher-domain and text-overlap estimate. Primary-source identification, entailment, paraphrase review and Added Value passages use AI and require a human. Lexical thresholds can over/underflag similarity; the tool is not a plagiarism database or factual accuracy guarantee. A low-value or fact-warning article can still be saved as a Draft for review.
- Exact already-processed source URLs are conservatively deduplicated. A materially new event reported at a new URL can pass fact fingerprinting; a changed story at the identical previously used URL requires explicit review/regeneration of the saved Draft. There is no “publish anyway” control.
- Background scoring is verified for Rank Math Free 1.0.278. Remote scripts require the WordPress 7.1 dependency hashes supplied by the build. PRO, custom editor JS filters and other dependencies are unverified and may be refused. Missing runtime does not prevent Draft creation; editor-based Rank Math remains available.
- WordPress cron depends on host scheduling/traffic. Each AI stage has bounded retries; a host killing PHP before the next checkpoint may repeat that stage. Completed article/image checkpoints are reused. Process-kill and high-load multi-host database stress testing was not performed.
- Explicit regeneration replaces text by design. Manual images/ALT and thumbnail ownership survive, but manual inline markup may move and surrounding captions/layout need review. Background recovery refuses detected human edits.
- Optional source pagination is bounded by the existing scoped listing/sitemap discovery; this candidate does not add an unbounded general crawler. Old audit files and 5.30/5.31 tests are historical, not current automatic-publication instructions.
- PHP syntax/runtime tested on 8.4.25; minimum 7.4 and arbitrary WordPress/plugin combinations were not separately executed. ZIP replacement on the actual website remains a staging/production rollout step.

## 12. Security evidence

Admin actions require manage_options, valid nonce, valid plugin-owned Draft, and edit_post capability; attachment actions validate article membership and edit permission. Replacement/removal actions require explicit confirmation. Malformed array fields are rejected before PHP string operations. Output is escaped and article HTML sanitized. Stored API keys are not rendered in admin HTML; blank key fields preserve them; logs redact stored keys and sensitive query parameters. Gemini uses a credential header with redirects disabled. Source URLs reject credentials/private literal addresses/local hosts/unsafe ports and use WordPress safe HTTP for redirects/DNS. Fetches have time/size/request bounds. Network failures and restricted pages have a six-hour retry record, and external link timeout does not attempt a bypass GET. Remote scoring retains signed, expiring requests, input fingerprints and exact dependency verification.

## 13–29. Required confirmations and their evidence

| Item | Result and evidence |
| --- | --- |
| 13. Generated posts Draft only | Confirmed in source and WordPress tests, including recovery/SEO writes |
| 14. Rank Math remains active | Confirmed with installed 1.0.278 analyzer producing genuine scores |
| 15. Rank Math never publishes | Confirmed at actual 76–85 scores and explicit saved 100; publication gate retired |
| 16. GDELT independent | Confirmed with GDELT-only fixture; live service not tested |
| 17. RSS independent | Confirmed with WordPress parser and RSS-only fixture |
| 18. Category URL independent | Confirmed with source-listing-only fixture |
| 19. Manual URL | Confirmed with all automatic sources OFF |
| 20. Source-prose writer boundary | Captured final writer/optimizer requests contain structured facts and protected instruction, no research prose sentinel |
| 21. Added Value | Evidence-selected passages, weighted score and warnings exercised; AI judgment remains unverified live |
| 22. Originality | Lexical copying fails; new-plan regeneration capped at two retries; incomplete semantic coverage is UNKNOWN |
| 23. Image ON | Actual distinct 1200×675 WebP files, featured not repeated inline, missing-only retry verified |
| 24. Image OFF | Zero generation HTTP calls, attachments and entry-point retries in tests |
| 25. Manual images | Thumbnail/body images/ALT retained; removal targets generated ownership only |
| 26. Post limit | Same run cannot exceed one or three successful Drafts; failed source does not fill a slot |
| 27. Block delay | Exact 21,600-second delay verified for 403, timeout, CAPTCHA and paywall fixtures |
| 28. Concurrency 1 | Global article lock and bulk mutex; one article per queue step; occupied worker defers selected work |
| 29. No automatic publication path | Active code audit found no wp_publish_post or plugin post_status=publish write. Remaining publish references are read-only queries/native publishing checks. Tests confirm human publishing still works |

## Delivery and release state

Installable ZIP: `globiqnews-fresh-ai-publisher.zip`; stable inner folder is unchanged. The separate GitHub source ZIP contains documentation, tests and optional scorer source, but no WordPress test installation, runtime downloads, environment file, passwords or real API key. SHA256SUMS.txt covers both ZIPs. The GitHub PR remains draft; automatic plugin updates need a later published stable release with the installable asset. This report does not claim production acceptance of every environment or successful live API generation.
