# SEO follow-up — 6.1.2

## Findings

The user confirmed version 6.1.1 and supplied screenshots showing 385 words, missing title sentiment/power words and missing internal links. The production article, saved settings and repair message were not available, so the exact production reason for the short draft has not been established.

Confirmed code behavior: sentiment and power-word warnings were explicitly excluded from repair. Internal-link matching accepted two shared title/metadata terms or an exact subject phrase in the body, but missed the same specific subject expressed with intervening or reordered words. Text repair could accept a title improvement even if an already-short body became substantially shorter. The action always announced completion instead of summarizing remaining saved targets. Unchanged candidates discarded their short_reason from the editor's unresolved-target display.

## Changes

- Conditional sentiment/power-word feedback reaches the writer and independent title generator. Power-word examples use the installed dictionary; the lightweight sentiment shortlist is still not a substitute for Rank Math's actual JavaScript analyzer. Factual/originality review must pass before saving.
- Reject substantial shortening of bodies already below 600 words, allowing minor copy edits (up to 10 words or 5%). Existing supported expansion targets remain 600 minimum / 650 or more suggested. Never synthesize padding to satisfy word count.
- Add bounded subject matching: all meaningful keyword terms must occur within 40 consecutive words in one sentence. Existing relevance matches remain. The published-only/no-password checks and three-link cap are preserved.
- Report measured saved length, title and link statuses, missing credentials/settings/research and failed review reasons. Retain short_reason when a returned candidate stays under 600 words, including unchanged candidates.
- Repair policy 612 gets one fresh three-attempt budget; repeated actions cannot reset it. Human edit protection remains.

Runtime files: includes/class-gnf5-seo.php, class-gnf5-writer.php, class-gnf5-titles.php, class-gnf5-runner.php and class-gnf5-admin.php. Also version/release docs and regression tests.

## Validation

333 distinct local assertions: pipeline 84, SEO601 30, SEO606 33, SEO611 41, new SEO612 25, originality/title 54, media/queue 36, security 30. Repeated shared tests counted once. All plugin PHP syntax checked; install ZIP structure and checksums checked. Image module and embedded image-action/composition methods compared against 6.1.1, along with untouched settings, queue, source, research, quality, publishing, updater and scoring modules.

New checks reproduce 385 words, reject a shorter 300-word title-only candidate, apply an evidence-reviewed 650-word fixture, verify actual Rank Math sentiment/power/internal-link maximum scores and absence of the under-600 warning, recognize nonverbatim subject context, reject distant/unrelated/private targets, preserve idempotence and OFF, retain unsupported-expansion reasons, enforce bounded retries and reject an unsupported positive title. Missing key and disabled optimization now explain why no rewrite ran.

Environment: disposable WordPress 7.1, PHP 8.4, SQLite, Rank Math Free 1.0.278. Source and Gemini responses are controlled fixtures, including the quality-review response. This validates processing and rejection logic, not real model factual judgment or writing quality. No production site, paid model call or user's live draft was tested. No guaranteed SEO score. Images unchanged, Draft-only and manual/concurrent-edit protection retained.
