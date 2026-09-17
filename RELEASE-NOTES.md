Version 5.30.0 - Genuine background Rank Math analysis

Runs the installed Rank Math Free 1.0.278 analyzer with Node.js after final article
text, slug, metadata, links, image attachments and ALT text have been saved.
The saved numeric score must be verified for those inputs and at least 80 before
automatic publication. Missing, invalid, stale, failed and lower scores stay Draft.

Adds bounded scoring retries (3 attempts), one optional existing-writer SEO repair,
metadata preservation, source/engine fingerprints, a central guarded publish path,
and one article worker across categories. The repair reuses existing images.
Adds hosting compatibility information, SEO status, retry count and failure reason
in settings. Saving settings and opening settings no longer perform slow analysis.
Settings, API keys, category sources, schedules and the GitHub updater are preserved.

Hosting requirements: Node.js 18+ executable, PHP proc_open, writable temporary
storage and Rank Math Free 1.0.278 with the verified analyzer. Other analyzer
versions and Rank Math PRO are not yet supported. No guessed score is substituted.
Use Analyze & Publish 80+ Drafts Now to retry after fixing runtime availability.

Validation: isolated WordPress 7.1 / PHP 8.4.25 / Rank Math 1.0.278; genuine scores
76,79,80,81,84,85; missing/invalid/noindex/disabled/unavailable states; stale article,
metadata, taxonomy and ALT changes; 3-attempt bound; category serialization;
existing finalizer and one-repair path with mocked Gemini transport and real local
attachments; settings preservation. Actual Gutenberg editor and Posts SEO Details
both displayed 80/100 for the article scored 80 by the background worker.
Live MilesWeb execution and third-party editor JavaScript customizations have not
been verified. Confirm hosting support on staging before enabling live updates.
