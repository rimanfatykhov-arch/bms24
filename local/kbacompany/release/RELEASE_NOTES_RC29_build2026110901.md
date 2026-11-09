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

Acceptance results are recorded in the accompanying PR report. Unresolved
unrelated failures remain blocking and must not be suppressed.
