# 6.0.1 — Non-image SEO and link repair

## Changes

Generated Drafts now receive local text checks and bounded, fact-checked SEO repair even without Node.js or a remote analyzer. The writer is given explicit keyword placement, natural density (about 1–1.5%), length (600 minimum when evidence supports it; 1000–1200 preferred), headings, title and paragraph guidance. Sentiment, power words and numbers are suggested only when supported. Short, neutral articles remain permissible; this is not an all-green or 80+ guarantee.

The content builder adds at most two external links: contextual researched primary-source links supporting retained facts, or reachable administrator-approved category URLs. Ordinary research URLs are not dumped into the body. Broken/restricted links are omitted. Editorial links are followable. Internal linking considers relevant published posts across categories and can use a small related-reading list when a natural in-text anchor is unavailable. No invented URLs, unrelated posts, drafts or password-protected posts are inserted.

The private editor report contains an independent non-image checklist and an **Improve SEO & Links** action for unchanged generated Drafts. It does not silently overwrite human edits. Repair attempts share one persisted maximum of three with genuine Rank Math repair. Identical/non-improving and failed-quality responses stop; provider failures retain the original Draft. Factual review now includes SEO title, description and excerpt.

Busy/network failures now report the real interruption without claiming that source discovery finished or found no candidates.

## Validation executed locally

- 28 WordPress foundation checks: settings/migration, ownership, locks, genuine Rank Math scores 76/79/80/81/84/85, Draft-only and manual publishing.
- 120 pipeline/media/queue checks (including 84 discovery/research checks): all discovery combinations, original writing boundaries, images OFF, real WebP files, media preservation, bounded repair, queues and batch caps.
- 27 security/lifecycle checks: permissions, credential handling, protected data, install/activation/deactivation.
- 30 new SEO regression checks: screenshot-like 437-word/two-keyword feedback; 600-word/density boundary; contextual/cross-category links; link exclusions; no fake scores; provider caps; human-edit races; failed factual review; editor report; real Rank Math link tests.
- Two JavaScript interaction tests: busy and failed requests preserve the actual error and make no repeated request.
- Six scoring-service HTTP tests: real analyzer results, authentication, replay/mismatch refusal, concurrency and error handling.
- PHP and asset JavaScript syntax checks; release version, archive integrity and credential-pattern scans.

205 distinct WordPress assertions passed. The SEO test harness also reruns the 84 pipeline checks; these are not double-counted. External research/Gemini/image responses are controlled fixtures. Real WordPress, Rank Math Free 1.0.278, WebP processing and the local HTTP scorer are exercised. One short research fixture scores 71 after repairing metadata/links: its limited length and neutral title are retained rather than padded. All three genuine Rank Math internal/external/followable-link tests pass. This is evidence of the repaired paths, not a promise of 80+ for every article.

## Scope and installation

No settings migration/reset is required. The 6.0.0 migration marker is deliberately retained. Saved API keys, categories, image choices and schedules stay in place. New per-post metadata records a repair note and a stopped-repair content hash. Image-generation behavior, manual image ownership and the Draft-only publishing rule are preserved.

Install `globiqnews-fresh-ai-publisher.zip` using **Replace current with uploaded**. Existing untouched Drafts can use **Improve SEO & Links** in their GlobiqNews report. Save/reopen the editor to see the actual Rank Math score. Manually edited Drafts need manual SEO edits or explicit reviewed regeneration; their text is never automatically replaced. The checklist works on hosting without Node.js; fully automatic genuine Rank Math scoring still needs the optional compatible runtime/service.

Production MilesWeb, live AI provider responses, and the user's specific article were not accessed or tested. AI factual judgments remain editorial aids. Relevant link availability and evidence-supported article length cannot be guaranteed. No plugin release can guarantee rankings or a particular SEO score. This report describes a tested update candidate; GitHub publication and live WordPress installation must be verified separately.
