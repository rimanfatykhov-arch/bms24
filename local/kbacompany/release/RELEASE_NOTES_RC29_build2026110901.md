# RC29 build2026110901 — communication and Corporate ERP compatibility

Isolated candidate; no merge, deployment or RC30 promotion authorization.

- Communication actor-name fallback selects the complete Moodle name-field set
  after existing company authorization; private-thread rules remain intact.
- Corporate is a locally patched0.40.10 lineage candidate2026110901/API1.26,
  not a replacement for unreconciled live0.40.13 feature trees.
- Code-only plugin savepoints identify this source accurately. No schema,
  account, company membership or role migration is introduced by this checkpoint.
- Full isolated Moodle matrix includes fresh and1645/1879/0890/0900 upgrades,
  schema preservation, focused communication/security cases and both suites.

PR52 isolated acceptance on MariaDB10.11.19 and Percona8.0.46-37: full Corporate
150 tests /962 assertions PASS; communication, privacy, task provider and
schema/tenant gates PASS. Full BMS558 tests retains23 errors/22 failures.
Overall acceptance remains HOLD; see docs/reconcile/PR52_RUNTIME_ACCEPTANCE.md.
