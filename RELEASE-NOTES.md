# GlobiqNews Fresh AI Publisher 6.0.2

Each category now has independent **Save Image Settings** and **Save External Link Settings** buttons. General category/global saves preserve those sections. AJAX saves validate permissions, nonce, complete payload and stale subsection revisions; unsaved edits are marked in the page.

- Category images: Use Global / ON / OFF, featured and inline slots, and WebP or optimized JPEG for newly generated images. Existing checkpoints and manually uploaded images remain protected.
- **Automatically Insert Research/Source Links in Article** defaults OFF on new installs and upgrades. Primary sources, legacy research URLs and other discovery evidence remain private unless explicitly enabled. Source collection, facts and internal links continue independently.
- Manual external links default OFF with an empty list. Add, edit, delete or move entries; set anchor text, purpose, enabled status and Optional/Preferred usage. Maximum 0–3 per article (default 2). Relevant, reachable links are inserted into existing paragraph text only; no appended source lists or forced links. Missing or unsuitable links do not fail the article.
- Private quality reports show link settings, counts and optional-link status. Rank Math still reports its genuine score and may flag its own external-link tests when links are intentionally omitted.
- All generated articles remain **Draft**, including at Rank Math 100.

After updating, open GlobiqNews Fresh AI → your category → Images or Manual External Links and use that section's Save button. The global research-link checkbox is in section 3, Rank Math SEO — Writing Targets. Save Global & General Category Settings saves that checkbox.

Existing articles are not mass-edited by this update or by saving settings. New drafts use the saved policy; for an existing unchanged generated Draft, Improve SEO & Links recomposes links under the current policy. Human edits are preserved. Link relevance is conservative text matching, not a guarantee of semantic accuracy; inspect Drafts before publishing.

Validation: 266 distinct isolated WordPress checks, two JavaScript status checks and six scoring-service checks passed, plus browser save/reload and layout checks. Live hosting and paid provider calls were not exercised. See TEST-REPORT-6.0.2.md.

Install through the existing WordPress updater, or upload globiqnews-fresh-ai-publisher.zip and select Replace current with uploaded. Do not delete the existing plugin.
