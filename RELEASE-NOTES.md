# GlobiqNews Fresh AI Publisher 6.0.5

Fixes an older settings tab silently overwriting newer category settings after a successful save. Category Save and the full-page Save now check the revision originally displayed. Conflicting forms report that nothing was saved instead of replacing newer values. A successful category save updates the page revision so subsequent category or global saves work without reloading.

Category saves verify the committed database row. Empty/truncated category requests cannot report success, and incomplete full-page forms are rejected before writing. Save feedback appears beside the category as well as at the top of the page. Images and Manual External Links retain their separate Save buttons and are preserved by general/global saves.

Checked general category fields, RSS/Source clearing, GDELT, author, timing, post limits, instructions, image controls, manual links, global controls, blank-key retention, stale tabs and queue regressions. All articles remain Draft. Existing settings and GitHub updates are retained.

After updating to 6.0.5, reload the plugin settings page once. If a conflict is reported, copy your unsaved edits, reload and apply them again. Update through WordPress → Plugins → Check for updates → Update now; do not delete the plugin.

Validation: 326 isolated WordPress checks and eight JavaScript checks passed. Real local browser saves, refresh persistence, stale-tab rejection and a current full-page save were verified. Production hosting was not tested; the reproduced overwrite path is confirmed, but it is not proof of the exact cause on your live site. See TEST-REPORT-6.0.5.md.
