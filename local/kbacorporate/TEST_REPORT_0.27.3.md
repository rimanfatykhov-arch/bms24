# Static test report — 0.27.3

- Version, XMLDB marker and upgrade savepoint match build `2026090281`.
- Upgrade changes are additive: a version table, tool draft/version fields and audit version fields.
- Published snapshots use deterministic field selection, Unicode-safe JSON and SHA-256 hashes.
- Tool and criterion updates flag only an existing published version as having a draft.
- Audit creation reads only the current published snapshot and stores its version ID and label.
- Existing audit items remain copied snapshots and are not rewritten during publication.
- Publication archives the preceding version inside one delegated transaction.
- PHPUnit coverage checks draft comparison, audit creation before publication, publication of `v2`, and audit creation after publication.
- Russian and English language keys, XML structure and Moodle link attributes are included in the local static gate.

PHP CLI and a Moodle test database are not available locally; runtime PHPUnit and upgrade verification remain required on the target Moodle server.
