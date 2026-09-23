# Validation — 6.0.3

Executed on isolated WordPress 7.1, PHP 8.4.25, SQLite and Rank Math Free 1.0.278. Provider HTTP responses use controlled fixtures, with external HTTP blocked. No production keys or paid requests were used.

| Suite | Passed |
| --- | ---: |
| New settings concurrency and manual source tests | 32 |
| Existing scoped settings, links and images | 61 |
| Foundation / migration / genuine score fixtures | 28 |
| Pipeline plus media/queue | 120 |
| Security / lifecycle | 27 |
| Non-image SEO regression (distinct assertions) | 30 |
| JavaScript status behavior | 2 |
| Scoring-service HTTP | 6 |

298 distinct WordPress assertions. Repeated pipeline checks are not counted twice. PHP and admin JavaScript syntax passed; installer/source archive integrity and version metadata were checked.

The new regression reproduces interleaved read/merge/write saves inside the real WordPress option update hooks. It verifies that the competing save reports busy, retry preserves both changes, and other categories and general/full-form writers use the same lock. Tests cover cached lock absence with a competing database row, ownership-safe release, stale lock recovery, stale revision, no-op and exception cleanup. Full-form testing exercises registration, nonce/capability checks and the subsequent option write in a local AJAX die-handler harness; this is not a live browser options.php submission.

Source-test assertions cover reachable/broken enabled manual URLs, disabled rows, master OFF, enabled-empty state, legacy/manual deduplication, research labels and category isolation. Existing suites verify permissions, settings retention, optional link policy, image controls, actual Rank Math scoring and Draft-only behavior.

Limits: isolated SQLite harness, not production MySQL or hosting. Database lock uses WordPress's unique option-name key with INSERT IGNORE. No live provider or production-site behavior is claimed. The genuine analyzer may still report a score below the preferred target. A busy save requires the user to retry; no unsaved edit is reported as successful.

Scripts in tools/tests require the disposable WordPress harness paths and must not run on a production site.
