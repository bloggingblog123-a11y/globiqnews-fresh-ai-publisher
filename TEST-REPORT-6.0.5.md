# Settings persistence validation — 6.0.5

## Reproduced problem

The user reported that all category settings say saved but disappear after refreshing. In isolated WordPress, a normal category save followed by reload retained the values. A second, older settings tab reproduced a concrete loss path: after the first tab saved post limit 9 and new instructions, saving the old full form restored post limit 7 and the old instructions. The 6.0.3 shared lock prevented simultaneous writes but did not detect a later write from a stale form. Images/links already had revision checks; general category and full-page settings did not. No production website or production database was inspected, so the live symptom may also involve another writer/cache/version issue.

## Changes

- General category saves send a revision and complete-field marker. An old revision cannot overwrite newer general values. The server returns the new revision; JavaScript updates the hidden form field after success, including saves performed before Run/Test actions.
- Full-page saves validate global and per-category revisions while holding the existing shared settings lock. A stale or truncated form stops before options.php writes anything. A final completeness marker detects PHP input truncation. Current full-page saves still save global and general category settings.
- Category/image/link save verification reads the committed database option and invalidates stale option caches. Empty category requests and incomplete new-client requests return errors rather than false success. Legacy partial category calls remain supported.
- Category feedback is displayed beside its Save button. General-save confirmation explains that Images and Manual External Links use their own buttons. Those independently saved sections are preserved by general/global saves.
- Version bump refreshes the admin JavaScript cache. Reload any settings pages opened before upgrading.

Runtime files: `includes/class-gnf5-utils.php`, `includes/class-gnf5-admin.php`, `assets/admin.js`, main plugin version header. Also updated readme, README, changelog and release notes; added `tools/tests/test-settings605.php` and this report.

## Executed checks

| Suite | Passed |
| --- | ---: |
| New field persistence / stale forms / complete requests | 75 |
| Existing scoped settings, images and links | 61 |
| Shared settings lock / manual source tests | 32 |
| Queue/RSS regression | 47 |
| Pipeline baseline run by queue suite | 84 |
| Security/lifecycle regression | 27 |
| JavaScript status and queue/save sequencing | 8 |

326 distinct WordPress assertions plus eight JavaScript checks. PHP/JavaScript syntax and installer/source ZIP integrity/version checks passed. External provider calls were controlled fixtures; these tests do not consume paid API quota.

The new suite verifies every general category field (including zero opportunity target, empty RSS/Source URLs and OFF flags), image controls, every manual-link setting, 28 global controls, blank Gemini-key retention, preservation of other categories/sections, stale general/image/link revisions, stale global forms, incomplete forms, empty payloads, lock release and Draft-only policy.

Actual local browser results:

1. Saved post limit, timing, sources and instructions; reloaded and verified saved values.
2. Cleared RSS using the keyboard, saved, reloaded and verified empty RSS persisted alongside post limit 9 and instructions.
3. Saved image mode OFF, featured OFF, inline ON and WebP OFF; reloaded and verified them.
4. Saved manual links ON, maximum 1 and a Samsung link with anchor/purpose; verified persistence.
5. Saved a newer general revision and verified an older category tab reported a conflict.
6. An older full-page form was rejected with the category-specific “Nothing from this form was saved” error.
7. In the current tab, a full-page Save changed the Gemini model and retained post limit 6, image OFF and manual links ON. This also verifies that category AJAX success refreshes the revision for a subsequent full-page submission.

Environment: isolated WordPress 7.1, PHP 8.4.25, SQLite, Rank Math Free 1.0.278. Test categories/user/settings were removed/restored and the local server stopped. No live MilesWeb, Redis/object-cache plugin or production MySQL testing is claimed. Already-open pre-update forms should be reloaded once; legacy unversioned partial API calls remain compatible and do not receive the new stale-form protection.
