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
