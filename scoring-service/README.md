# GlobiqNews Rank Math scoring service

This optional service runs Rank Math Free 1.0.278's actual JavaScript analyzer for
WordPress hosts that cannot run Node.js. WordPress keeps the article queue, retry
state and manual publishing. Version 6 always keeps articles as Draft, regardless of score.
This service is optional: without it, create Drafts and use Rank Math in the editor.

## Render setup

Create a **Web Service** from this repository. For staging, select the existing
draft PR branch `fix/rankmath-background-score-5.30.0`, which now contains 6.0.0.

| Field | Value |
| --- | --- |
| Name | `globiqnews-rankmath-scorer` |
| Language | Node |
| Root Directory | Leave empty |
| Build Command | `node scoring-service/build.cjs` |
| Start Command | `node scoring-service/server.cjs` |
| Instance Type | **Free** |
| Health Check Path | `/health` |
| Auto Deploy | Off during staging |
| Environment: `NODE_VERSION` | `22` |
| Environment: `GNF5_SCORER_SECRET` | A new random secret, at least 32 characters |

Alternatively, Render's Blueprint flow uses `render.yaml`, explicitly selects
`plan: free`, and generates the secret automatically. Copy that secret privately
from Render's Environment page into the WordPress plugin. No Gemini or WordPress
credentials belong in Render. No database, disk or paid resource is needed.

After deployment, `https://YOUR-SERVICE.onrender.com/health` should show `status: ok`.
Install the 6.0.0 candidate ZIP using **Replace current with uploaded**. In plugin
settings choose **My HTTPS scoring service**, enter the base HTTPS address (without
`/health`), enter the matching secret, tick article-sharing permission, and save.
Use **Recheck Draft SEO Scores** for a controlled draft test.

Render Free sleeps after 15 minutes idle and can take about a minute to start.
WordPress waits at most 20 seconds and then keeps Draft, retrying after about 5 and
30 minutes, maximum 3 attempts. WordPress cron must run on time. After exhausted
attempts, restore/wake the service and use the manual retry button. There are no
paid upgrades, keep-alive pings or infinite retries in this code.

Render advises against production use of free instances; quota limits can suspend
them. This is a free testing option, not guaranteed production availability. Without
a payment method, Render suspends service rather than billing usage overages.
See current terms: https://render.com/docs/free

## Compatibility and data

The build downloads official Rank Math 1.0.278 and WordPress 7.1 ZIPs, verifies
pinned SHA-256 checksums and extracts required scripts and notices. It does not
run WordPress/PHP on Render and needs no npm packages. Every request script hash
must match the service. Different dependencies are refused until verified; confirm
the website's WordPress version before connecting it.

Requests contain final article text/title/description/keywords, schema, permalink,
image URLs/ALT, Rank Math settings and an input fingerprint. Images and linked pages
are not downloaded. Writer keys, WordPress passwords, script paths and executable
code are excluded. Requests/results use HMAC-SHA256 and unique request IDs; requests
expire after five minutes. WordPress enforces HTTPS and refuses redirects. Render
terminates HTTPS; Node listens on its assigned PORT. Keep the shared secret private.

Only one worker thread analyzes at a time, with a 15-second timeout and memory
limit. Scripts are fixed at build time. The service has no article database, writes
no articles, and logs only readiness and generic errors. Article data stays in
memory during analysis; the hosting provider necessarily processes that data.
Publication is exclusively controlled by the WordPress plugin.

## Local verification

From the repository root, run `node scoring-service/build.cjs`, then
`node scoring-service/test.cjs`. Start the server with GNF5_SCORER_SECRET set.
GET `/health` needs no credentials; POST `/v1/analyze` requires authenticated JSON.
The disposable WordPress integration test is in `tools/tests/test-remote531.php`.
See `TEST-REPORT-5.31.0.md` for tested behavior and live-test limitations.

## Third-party source

Rank Math is GPLv3-or-later: https://github.com/rankmath/seo-by-rank-math and
https://wordpress.org/plugins/seo-by-rank-math/. WordPress is GPLv2-or-later:
https://github.com/WordPress/WordPress/tree/7.1. The build retains original scripts,
WordPress's license, Rank Math's readme license notice and Lodash's MIT notice.
Generated runtime files are excluded from the plugin ZIP and the Git repository.
