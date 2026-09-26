# Non-image SEO repair — 6.0.6

## Findings and change

The existing pipeline already ran `optimize_text()` before background scoring, without requiring Node.js. The earlier explanation that all repair depended on successful scoring was incomplete. Its local repair acceptance compared only the number of failed checks. It could discard a useful 437-to-520-word improvement because the 600-word check still failed, or accept a net reduction that regressed another passing check. The opening check also accepted a keyword in a heading rather than the first paragraph. Existing exhausted repair budgets prevented changed repair guidance from being tried on old unchanged drafts.

Generation and repair now prioritize the opening paragraph, evidence-supported expansion to 600+ words, and a relevant factual title number or truthful useful list count. `text_repair_progress()` rejects keyword drift and any regression of an already-passing local text check. It accepts a newly passing check or at least 60 additional words when the original was below 600; factual/originality review remains mandatory before saving. The local checklist is not a Rank Math score. An unchanged generated draft gets one new three-attempt budget for this policy when processed again; repeated clicks cannot reset it. Human-edited drafts are rejected before resetting or calling AI.

No image settings, image-generation code, scoring engine, queue dispatch or publication rules changed. Draft-only behavior remains enforced. Limited source evidence can still leave a shorter article requiring review; no fabricated facts, arbitrary dates or guaranteed 80+ score are introduced. Existing drafts can use the editor's Improve SEO & Links action; it retains its existing link behavior.

## Executed validation

- 84 pipeline baseline assertions passed.
- 30 existing non-image SEO assertions passed, including real Rank Math 1.0.278, invalid factual repair rejection, concurrent manual-edit preservation and bounded attempts. The provider fixture was updated to retain the previously passing power word, matching the new no-regression requirement.
- 33 new assertions passed: opening paragraph versus heading, 437-to-520 partial expansion, continued expansion above 600, rejection of title-number gains that lose introduction/length checks, keyword preservation, unchanged-response rejection, factual-number prompt constraints, old exhausted draft retry, new budget persistence, image metadata preservation and Draft-only behavior.
- The real Rank Math analyzer passed keyword-in-first-10%, title-number, title/description/URL/body keyword, title-start and short-paragraph tests on the repaired fixture. Its content-length feedback recognized 651 words and stopped asking for at least 600; this is not the maximum length score.
- 36 additional media/queue regression assertions passed, including image ON/OFF and existing image recovery behavior.
- Total: 183 distinct assertions, excluding repeated pipeline runs. PHP syntax and installer/source archive integrity checked during packaging.

Environment: isolated local WordPress, SQLite, PHP 8.4, Rank Math Free 1.0.278. External AI/research responses were controlled fixtures, not live paid calls. The length-boundary fixtures contain synthetic text and test plumbing/thresholds, not real editorial quality. The actual Rank Math JavaScript analyzer was executed locally. No production MilesWeb test or guarantee of real article scores is claimed. Hosting Node.js limitations and strict background analyzer compatibility remain unchanged.

Runtime files: `includes/class-gnf5-seo.php`, `includes/class-gnf5-runner.php`, `includes/class-gnf5-writer.php`, plus version header. Documentation and isolated test fixtures also updated.
