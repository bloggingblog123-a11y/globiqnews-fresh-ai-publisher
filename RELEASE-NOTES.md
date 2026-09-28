# GlobiqNews Fresh AI Publisher 6.1.3

Fixes the order that could replace a keyword-aware SEO title with an earlier title chosen before the focus keyword. Independent headline generation now receives the final body and keyword, and retries a title that does not start with the exact phrase. Originality and factual review remain required.

Text repair checks the exact keyword in the title, description, URL, opening and headings, then supported length, density and title readability. An unchanged model response without an evidence limitation can use the remaining three-attempt budget, with rejection feedback. Evidence limitations and factual-review failures still preserve the original Draft. Saving a draft is not reported as SEO success. The report shows saved checks including title, description and density.

Legacy SEO metadata is adopted only when it exactly matches an unchanged generated Draft's checkpoint. Conflicting custom metadata blocks repair instead of silently leaving an inconsistent title/description. Internal linking still prefers related public articles; when none matches, it can link the current category archive if that category has public, unprotected published content. OFF remains respected.

Images, external-link controls, paid Content AI, queueing, settings, Draft-only publishing and GitHub updater behavior are unchanged. Installation does not rewrite old posts or published articles. For an unchanged generated Draft, use GlobiqNews Original Content Report > Improve SEO & Links and reopen Rank Math. Human edits remain protected.

Local tests reproduced 556 words, two keyword occurrences (0.36%), missing title/description keywords and no internal link. The corrected fixture reached 650 words and passed the actual Rank Math Free 1.0.278 title, description, title-start, density, internal-link and minimum-length tests. These use controlled source/model responses; they do not guarantee live AI output, factual sufficiency or a particular overall score. See TEST-REPORT-6.1.3.md.
