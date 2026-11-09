# Communication and Corporate ERP runtime repairs

Date:2026-11-09. Isolated review branch `fix/pr50-runtime-blockers` based on
PR50 commit5c59057. BMS and the locally patched Corporate candidate both identify
as2026110901. Corporate remains0.40.10 source lineage/API1.26, not live0.40.13.
No production/staging changes, merge or deployment are authorized.

## Scope and evidence

PR50's real MariaDB/Percona JUnit exposed incomplete Moodle name projections,
missing-mapping TypeErrors, Percona syntax errors around `system`, a Corporate
collection fatal and invalid test fixtures. This patch addresses those concrete
communication/Corporate blockers without weakening ACL/privacy checks.

- Communication uses the complete Moodle name-field set for its existing
  company-authorized actor fallback. A named-user regression exercises additional
  name fields and asserts no debugging. A stale inbox expectation is corrected
  to explicitly verify two distinct events: thread invitation and message.
  Relative future/past deadlines separately verify `task_due` and `task_overdue`,
  so the test no longer changes meaning after a fixed calendar date.
  Privacy context assertions normalize DB-returned numeric IDs before strict
  comparison; all anonymization and other-participant preservation assertions
  remain. Corporate task-source URLs are compared in full against Moodle's URL
  API, including the configured origin and installation subdirectory.
- ERP normalizes a missing DB result to null at its private typed boundary.
  Company/provider/external-ID predicates, opt-in and membership checks remain.
- ERP lookups qualify the fixed `system` column, with all values bound. Fixed
  column INSERTs quote that identifier for MySQL-family engines, including audit
  logging; updates omit the unchanged provider identity. No column is renamed.
- Criterion save preserves valid section code `'0'`; it must not be treated as
  an absent value by PHP empty(). Existing weighted-score assertions remain.
- Development-plan projections select every real Moodle name field, including
  joined mentor, evidence submitter and reviewer names. Query scope is unchanged.
  Normal role-merge outcomes remain structured results rather than developer
  errors; ERP privacy-safe audit logging remains enabled.
- Corporate tests declare CFG in the file-loader scope, avoid by-reference calls
  on expressions, use Moodle generators/valid domain fixtures, supply intended
  manager permissions, and read privacy metadata through its actual object API.
  Text comparisons use Moodle's DB-portable API and mutating evidence tests reset
  their fixtures. Provider isolation is tested independently within one company
  as well as across companies, including missing mappings and hashed audit data.
  No failure is suppressed, skipped or changed to an expected exception merely
  to make the suite green.

Code-only savepoints record the new versions. The0900 index/field repair remains
unchanged. No new schema, roles, membership or business-data migration is added.
Independent read-only review preserves tenant/provider scope and audit hashes.

## Source provenance and validation

The original Corporate archive version/hash and817-file inventory are retained
in `corporate-api126-source.json`. An explicit local patch section records each
changed file's prior/current normalized SHA256 and the candidate version. This
is a reviewed source patch, not a claim that the upstream archive contained it.

The isolated matrix runs on stock Moodle4.1.22/PHP8.1 with pinned MariaDB10.11.19
and Percona8.0.46-37 containers. It includes fresh install,1645/reconstructed1879/
0890/0900 upgrades, exact183-table schema parity, preserved synthetic tenant data,
strict migration/index regressions, dedicated communication suites and both full
plugin suites. Unrelated BMS failures remain blocking until independently fixed.

Execution results will be recorded after the actual runs. No full acceptance or
deployment approval follows from this implementation note alone.
