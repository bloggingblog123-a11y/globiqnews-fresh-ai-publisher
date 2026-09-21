# Verification — 5.31.0 candidate

## Result

The actual Rank Math Free 1.0.278 analyzer returned genuine scores 76, 79, 80, 81,
84 and 85 through the scoring service. With PHP proc_open disabled in the WordPress
test process, 80+ published through the central gate and lower/unavailable scores
stayed Draft. This is local verification; the user's live site has not been tested.

## Checks performed

- 60 WordPress integration assertions over the remote transport: genuine scores,
  79/80 boundary, missing/invalid/noindex scores, scheduled processing, stale article,
  metadata and taxonomy changes, three-attempt bound, category serialization,
  duplicate-publication guards and settings preservation; plus failed authentication,
  wrong fingerprint/request ID, out-of-range/string scores, unavailable service,
  permission off, invalid service URLs and partial settings saves.
- 11 extended checks: existing writer repair with mocked Gemini HTTP, actual analyzer
  rescoring to 81, existing images reused, customized metadata preserved, image ALT
  changes invalidate receipts, publication-filter changes cannot bypass the gate,
  and the existing article finalizer commits content/images/meta before scoring.
- Six Node service tests: health, authentication, real score fixtures, replay/expiry,
  dependency mismatch, refusal of executable paths, concurrency=1 and missing keyword.
- The 45 original WordPress checks also passed again in local Node mode after the
  remote transport was added; local mode remains available on compatible hosts.
- All 57 repository PHP files passed syntax checks.
- The build downloaded the pinned official WordPress and Rank Math archives and
  verified their full SHA-256 checksums; runtime dependency hashes match WordPress.

## Test environment and limits

Disposable WordPress 7.1, PHP 8.4.25, Rank Math Free 1.0.278 and the actual analyzer.
Requests carry synthetic articles only. A test-only WordPress HTTP filter checks
HTTPS, certificate verification, redirect refusal, unsafe-URL rejection and timeouts,
then bridges the exact authenticated body to a real loopback HTTP server. PHP process
execution was disabled for the full remote suite. No fabricated score bypass was
used in passing cases; negative tests intentionally corrupt responses to verify rejection.

The prior 5.30 local test compared a background score of 80 with the actual Gutenberg
Rank Math sidebar and Posts SEO Details, both showing 80/100. The same analyzer and
input construction are used remotely. Browser parity was not repeated for each fixture.

Render deployment, real TLS between MilesWeb and Render, cold-start duration under
Render load, and the user's WordPress version remain unverified. The supplied service
matches WordPress 7.1 dependency hashes. Other dependency versions fail closed until
verified. Rank Math PRO and arbitrary editor JavaScript extensions are not supported.
The SQLite test installation's Rank Math migration/index warnings are unrelated to
the tested scoring paths; this is not a production database compatibility test.

## Changed areas

- `includes/class-gnf5-rankmath.php`: optional HTTPS transport, authenticated result
  validation and dependency fingerprints; existing publication gate remains central.
- `includes/class-gnf5-utils.php`, `includes/class-gnf5-admin.php`: transport settings,
  saved secret handling and explicit article-sharing permission.
- `scoring-service/`: pinned build, bounded analyzer worker, authenticated HTTP server,
  tests and setup guide; `render.yaml` explicitly selects Free.
- Version header/readme/changelog and release/setup documentation updated to 5.31.0.

No stable release was published, no service was deployed, and no live WordPress
files, settings or posts were changed during preparation.
