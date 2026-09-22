# GlobiqNews Fresh AI Publisher 6.0.1

Fixes non-image SEO gaps in generated Drafts: verified external links are now inserted, relevant published internal-link matching is broader, and local text checks/quality-checked optimization work without a Node.js or remote scoring service.

- Guides keyword use in the title, description, URL, introduction, headings and body; targets natural density and useful evidence-supported length.
- Suggests accurate title sentiment, power words and numbers without inventing claims or forcing filler.
- Adds a private non-image checklist and **Improve SEO & Links** for unchanged generated Drafts.
- Retains a shared three-attempt repair limit, stops unproductive rewrites, and checks SEO metadata as part of factual review.
- Fixes misleading “no source candidates” messages when a run is actually busy or interrupted.
- Preserves image settings, manual edits/media, credentials, schedules and Draft-only publishing.

Validation: 205 distinct local WordPress assertions, two JavaScript interaction checks and six scoring-service tests passed. The actual Rank Math 1.0.278 internal/external/followable-link tests pass on the repaired fixture. Live provider/hosting behavior remains unverified. See TEST-REPORT-6.0.1.md.

Use the normal WordPress update action when this release is published, or upload the plugin ZIP and select **Replace current with uploaded**. Do not delete the old plugin. For existing untouched Drafts, open the GlobiqNews quality report and choose **Improve SEO & Links**, then reload the editor to see Rank Math's current score. Human-edited text is preserved. A real background score still requires the optional compatible analyzer; local diagnostics never invent a Rank Math score. Articles remain Draft regardless of score, and 80+ is a target rather than a guarantee.
