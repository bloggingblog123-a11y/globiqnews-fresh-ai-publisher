# Version 6.0.0 tests

Run ONLY against a disposable local WordPress installation. Tests change local settings and create/delete test posts/media. Never run these on a production or shared database. The bootstrap refuses web execution and non-local sites.

Required: WordPress 7.1, Rank Math Free 1.0.278, this plugin active, local admin ID 1/category ID 1, PHP DOM/mbstring/GD with WebP/SQLite (or a disposable MySQL database), Node.js 18+, and GNF5_NODE_BINARY set to its real path. Set WP_ENVIRONMENT_TYPE=local, DISABLE_WP_CRON=true, WP_HTTP_BLOCK_EXTERNAL=true, site URL http://globiqnews.localhost:8097. Set environment variable GNF5_TEST_WP_ROOT to the WordPress directory. External requests must be blocked by a test mu-plugin unless an earlier pre_http_request fixture supplied a response. No paid API keys are required.

Run sequentially, from this folder, with your configured PHP runtime:

```text
php test-master-foundation.php     # 28 actual WordPress / Rank Math checks
php test-master-media-queue.php    # includes pipeline; 120 checks total
php test-master-security.php       # 27 permission / evidence / edge checks
```

The media/queue test includes test-master-pipeline.php (84 checks). Do not double-count those 84. Gemini, image-provider and discovery HTTP responses are fixtures. Built-in WebP generation and the installed Rank Math analyzer are real. Read the asserted TOTAL and process exit code; a shell display command must not mask a PHP failure. In scoring-service, `node test.cjs` runs six actual HTTP/analyzer tests after building the pinned runtime.

## Historical version 5 tests

The files ending 530/531 below are retained as historical evidence for those versions. Their automatic-publication assertions intentionally do not describe version 6 and must not be used as version 6 acceptance tests.

Run only against a disposable local WordPress installation at http://globiqnews.localhost:8097 with the official Rank Math Free 1.0.278, this plugin, and default Rank Math setup activated. Set WP_ENVIRONMENT_TYPE to local, disable WP-Cron and external HTTP, configure a valid GNF5_NODE_BINARY, and set GNF5_TEST_WP_ROOT to that WordPress directory.

Run php test-rankmath530.php, then php test-repair530.php (not concurrently). The second test mocks Gemini via pre_http_request; it performs no paid API calls. It creates local PNG attachments. These tests create posts, change test settings, and must never run on a live site. The runtime-failure test additionally needs a test-only mu-plugin that removes Rank Math from option_active_plugins for GNF5_TEST_NO_RANKMATH, and test wp-config handling of GNF5_TEST_NO_NODE to select a nonexistent Node path.

Fixtures are article inputs genuinely producing the expected scores in the verified engine. The integration tests execute the installed analyzer; expected values are assertions, never substituted analyzer outputs.

For the 5.31 HTTPS transport integration test, build the scoring-service runtime first.
Set GNF5_TEST_SCORER_SECRET_FILE to an absolute temporary path outside the repository;
run node tools/tests/serve-scorer-test.cjs to create that test secret and listen only
on 127.0.0.1:8098. In another shell set the same secret-file path and GNF5_TEST_WP_ROOT,
then run php -d disable_functions=proc_open tools/tests/test-remote531.php with the
required local PHP extensions. Stop the local server after testing.

This test reuses the 45 boundary/freshness/queue assertions and 11 repair/finalizer
checks, then adds remote authentication, mismatch, timeout, consent and URL checks.
The WordPress HTTP filter verifies production HTTPS/redirect/SSRF options and bridges
synthetic data to the real loopback HTTP service. It does not send test articles to
Render and does not verify production TLS or MilesWeb networking. Production code
has no loopback exception. The wrapper restores settings on shutdown; created test
posts remain in the disposable database.
