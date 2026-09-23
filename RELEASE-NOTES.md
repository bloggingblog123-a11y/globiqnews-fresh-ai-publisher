# GlobiqNews Fresh AI Publisher 6.0.3

Fixes two issues found in 6.0.2:

- Overlapping settings saves could both report success while one overwrote the other. Category image, manual-link, general and global form saves now share an atomic database lock. If another save is in progress, the plugin clearly reports that your changes were not saved and asks you to retry. Completed saves preserve other sections and categories. Existing stale-tab protection remains in place.
- **Test RSS + Sources + External Links** now checks saved, enabled Manual External Links for the selected category. Results identify reachable and broken links. Disabled entries are skipped, the master OFF state is explained, and duplicate manual/legacy URLs are checked once. Legacy private research links retain a separate label.

Update through WordPress → Plugins → Check for updates → Update now. Do not delete the installed plugin. If a save reports busy, wait for the other save to finish and click the same Save button again.

Existing settings, API keys, image preferences and the version 6 Draft-only workflow are preserved. This patch does not automatically publish or mass-edit articles.

Validation: 298 distinct isolated WordPress assertions, two JavaScript status checks, six scoring-service checks, PHP/JavaScript syntax and ZIP integrity checks. See TEST-REPORT-6.0.3.md. Production hosting and paid APIs were not tested.
