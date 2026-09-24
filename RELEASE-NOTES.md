# GlobiqNews Fresh AI Publisher 6.0.4

Queued categories now continue in the background as soon as a worker slot is free. Run Selected Categories and Run All save the selected categories first, persist the entire queue, and start available workers. Run Category Now joins the same queue. Maximum Concurrent Categories supports 1 or 2 and is in section 6, Advanced source safety & recovery. The queue display refreshes automatically.

Completed, exhausted, failed, blocked and cancelled workers finalize counters and logs, release their category lock and slot, then dispatch the next eligible job. Empty or blocked sources cannot occupy a worker for six hours. Source retries have independent events. Atomic claims and token ownership prevent duplicate workers and cross-request lock release. Cron is a watchdog/fallback; normal completion sends an immediate background request to the next worker.

Saved RSS settings are authoritative. An empty RSS field means zero RSS feeds, including Run All. Source URLs no longer silently import feeds advertised in HTML. To use an RSS/Atom URL, put it explicitly in RSS Feeds. Removing a configured source cancels its pending retry. Source tests show configured/tested counts, HTTP status, content type, parse result, candidates, blocked reason and retry time.

All generated articles remain Draft. Existing settings, authors, post limits, images, link controls, Rank Math, recovery and GitHub updates are retained.

Update through WordPress → Plugins → Check for updates → Update now. Do not delete the installed plugin. The normal background queue requires WordPress to accept its own admin-ajax.php loopback requests. If hosting blocks these, the debug log reports it and cron recovery remains available.

Validation: 345 distinct isolated WordPress assertions, eight JavaScript checks, six scoring-service checks, PHP/JavaScript syntax and ZIP integrity. An actual local HTTP queue drained three categories with WP-Cron disabled. See TEST-REPORT-6.0.4.md for all 14 requested findings and test limits. Production hosting was not tested.
