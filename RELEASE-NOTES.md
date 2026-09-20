# GlobiqNews Fresh AI Publisher 5.31.0 — staging candidate

Shared hosting without Node.js or PHP proc_open can now use an authenticated HTTPS
service to run Rank Math's real analyzer. Article generation, images, stored keys,
categories and the central 80+ publishing gate remain in WordPress.

Choose **My HTTPS scoring service** under Auto Publish, enter the service address
and matching secret, enable article-sharing permission, and save. Final article
text, SEO metadata, links, image URLs/ALT and analyzer settings are sent for scoring.
Writer keys and WordPress passwords are excluded. The service does not fetch links,
store articles or publish posts. WordPress authenticates the result and checks
that the article has not changed before accepting and saving the genuine score.

The supplied service matches Rank Math Free **1.0.278** and WordPress **7.1** analysis
dependencies. Different hashes or engine versions fail closed until verified.
Local Node scoring remains available on hosts that support it.

See [setup instructions](scoring-service/README.md). Render Free has usage limits
and cold starts: this is a free testing option, not guaranteed production hosting.
Unavailable scoring leaves articles Draft, with at most three attempts. Use the
manual analysis button after restoring the service if the retry budget is exhausted.

Local verification used actual WordPress, genuine analyzer results, real loopback
HTTP, and PHP proc_open disabled. Live Render deployment and the user's MilesWeb
installation have not yet been verified. Keep this candidate out of stable automatic
updates until that test. Existing settings and category data are preserved.
