# Validation — 6.0.2

Executed locally on isolated WordPress 7.1 / PHP 8.4.25 / SQLite with actual Rank Math Free 1.0.278. Research/writer/image-provider HTTP replies are controlled fixtures; no production credentials or paid API requests were used.

| Suite | Passed |
| --- | ---: |
| Foundation / upgrade / actual analyzer fixtures | 28 |
| Pipeline + media/queue | 120 |
| Security and lifecycle | 27 |
| Non-image SEO regression, adapted to explicit link consent | 30 |
| New scoped saves, URLs, link policy, image slots/formats | 61 |
| JavaScript busy/network status behavior | 2 |
| Actual scoring-service HTTP suite | 6 |

266 distinct WordPress assertions; repeated pipeline runs are not double-counted. PHP syntax and admin JavaScript syntax passed.

New tests cover the real registered settings sanitizer with image → external → general saves; omitted fields; another category; stale revision; complete-payload, capability and nonce rejection; private/malformed/JavaScript/data/file URLs; sanitized anchor/note; relevant Samsung and irrelevant Apple; source links OFF across RSS/GDELT/source/manual/reference provenance; legacy research URL isolation; empty/disabled lists; zero limit; duplicate domains; opt-in primary sources; independent internal links; raw source URL/credit/reference removal; featured/inline selection; JPEG/WebP output and dimensions; disabled checkpoint retention; and Draft at score 100.

Browser checks on the isolated WordPress settings page confirmed image save, adding/editing a manual row, link save, category-specific success messages, persisted values after reload, unsaved notices and readable layout. No production-site behavior is claimed. Relevance uses conservative lexical matching rather than live semantic verification. Existing generated attachments retain their format; new files use the selected preference. The existing optional scoring service/runtime is unchanged. Disabled optional links can still reduce Rank Math's actual score, which is never fabricated.

Test scripts in tools/tests are fixtures for an isolated WordPress installation and require their documented local harness paths; they must not run against a live site.
