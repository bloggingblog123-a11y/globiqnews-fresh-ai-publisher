# SEO validation and repair — 6.1.3

## Confirmed causes

1. create_article generated independent titles before the article/focus keyword and then overwrote the writer's SEO title with the earlier title. The title generator did not require the exact keyword or its placement at the beginning.
2. A single unchanged or regressing SEO response stored a permanent stopped marker for that article, even if the three-attempt budget had not been used and no evidence limitation was supplied.
3. save_rank_math intentionally protected unowned metadata. An older generated Draft without ownership records could retain old SEO fields while its body/checkpoint changed. Repair now adopts only exact checkpoint matches and stops on a conflict.
4. No sufficiently related published post meant no internal link. A public category archive is now an explicit navigation fallback, rather than inserting an unrelated story.
5. Action feedback omitted several requested saved-field checks. It now reports all text targets, and records outstanding targets as needs_review. This is a local checklist, not a fabricated Rank Math score.

Production was inspected read-only: installed plugin 6.1.2, Rank Math 1.0.279. Draft 3387 showed 654 words, passing description/URL, missing keyword in SEO title and opening. The earlier 556-word screenshot's exact post was not identified. This is not proof of that post's full generation history. No production post has been rewritten by these tests.

## Validation

374 distinct assertions: pipeline 84; SEO601 30; SEO606 33; SEO611 41; SEO612 25; new SEO613 41; originality/title 54; security 30; media/queue 36. Repeated baseline checks counted once. The first new run exposed local category-archive validation rejecting the staging host; that path now compares WordPress-generated local URLs without using the external-source URL validator. A historical assertion expecting one unchanged attempt was updated for the explicit three-attempt behavior; the bounded stop and no-repeat behavior remain verified.

New tests verify body-before-title ordering, rejection of a title missing the keyword, the 556-word/0.36% reproduction, safe category navigation and idempotence, OFF, an unchanged first AI response followed by a valid second response, saved metadata consistency, all 13 requested text/link checks, actual Rank Math title/description/title-start/density/internal-link/length outcomes, unchanged Draft/image state, exhausted retries with needs_review, protected conflicting metadata and exact phrase boundaries.

All plugin PHP files are syntax checked. Release packaging validates header/constant/stable-tag agreement, ZIP integrity and structure, and unchanged image, external-link, publishing, queue, settings, source, quality, scoring and updater code outside the scoped methods. Checksums accompany the ZIP.

Environment: disposable local WordPress 7.1, PHP 8.4, SQLite and Rank Math Free 1.0.278. AI generation/review and sources are controlled fixtures; the Rank Math analyzer is real. Production uses Rank Math 1.0.279, which was not validated by these local analyzer tests. Live Gemini wording, live factual review, hosting and the user's complete affected article were not tested. No guaranteed score or automatic repair of human-edited/published posts. Images and external links are outside this change.
