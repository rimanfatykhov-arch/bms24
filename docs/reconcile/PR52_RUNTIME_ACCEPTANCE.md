# PR52 — communication and Corporate runtime acceptance

Date: 2026-11-09. Draft PR52, branch `fix/pr50-runtime-blockers`, based on
unmerged PR50 (`5c59057cb7af3fd545cd661bb7c2b3daa5ae7cd9`). Both plugin
versions are 2026110901; Corporate is locally patched 0.40.10/API1.26 lineage.
It is not a deployment candidate for the unreconciled live 0.40.13 feature tree.

Tested source: `469afada154f2a2817c1383cdf399fb040449030`.
GitHub synthetic merge tree: `46c76368faa44643151543f6990a42c639c490c3`.
[Full matrix 42584235417](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42584235417)
uses Moodle4.1.22 core `df1157bb845eabedd31989d6a1a6e18f638ff890`, PHP8.1.34,
PHPUnit9.5.28, pinned MariaDB10.11.19 and Percona8.0.46-37 containers.
Production, staging and shared local Moodle QA were not modified.

## Changes and boundaries

See [implementation and provenance](COMMUNICATION_CORPORATE_RUNTIME_FIXES.md).
The patch fixes complete Moodle name projections, missing ERP mappings, reserved
SQL identifiers, code `'0'` handling, normal role-merge debugging and invalid
Moodle test fixtures/discovery. Name/date/provider/audit regressions are added.
Privacy anonymization assertions and full local task URL checks remain strict.
No schema/ACL/role migration, runtime privacy relaxation or upstream file removal
is introduced. The code-only savepoints identify the patched source. The pinned
817-file Corporate inventory and explicit before/after patch hashes are checked.

## Actual matrix

| Gate | MariaDB | Percona |
|---|---|---|
| Fresh install; upgrade1645/reconstructed1879/0890/0900 | PASS | PASS |
| Exact183-table schema parity, defaults/data preservation, repeated upgrade | PASS | PASS |
| Field repair regression |21 tests /2489 assertions PASS |same |
| Ordered index regression |8 tests /81 assertions PASS |same |
| Two-company non-admin isolation |2 tests /66 assertions PASS |same |
| Communication (three focused files) |23 tests /85 assertions PASS |same |
| BMS privacy/anonymization |6 tests /37 assertions PASS |same |
| Corporate task provider projection |4 tests /23 assertions PASS |same |
| Full Corporate PHPUnit, including ERP |150 tests /962 assertions PASS |same |
| Full BMS PHPUnit |558 tests /5397 assertions;23 errors /22 failures |same |
| Overall release acceptance |**FAIL / HOLD** |**FAIL / HOLD** |

Every reported suite has zero skipped tests. Corporate runs as a full suite;
per-file fallback is no longer necessary. Both engines have identical counts
and failing identities. Relative to the first PR52 run (6756ed9),8 BMS failures
are resolved and no new failing identities appear; Corporate went from149 tests,
14 errors/2 failures to150 tests with no errors/failures. The new name projection
regression and both future/past communication deadline cases pass.

The full release gate remains **HOLD** while full BMS tests fail. Passing focused
tenant tests is not evidence that all application ACL paths are accepted.

The 1879 origin is a reconstructed source/dependency tree, not a production clone.
The 0890 and 0900 origins are exact PR48 and PR50 source commits. All four upgrade
origins must match the fresh schema across all 183 plugin tables, retain synthetic
tenant data/defaults and pass repeated upgrade without drift.

## Remaining work

The remaining BMS failure identities are preserved in `pr52-runtime-results.json`.
Independent QA found no new failures compared with the first PR52 iteration.
Suggested isolated follow-ups, subject to reproduction rather than blanket
expectation changes: company/document authorization and UI permission paths;
RC30 error classification/comparison gates; then domain state/calculation,
fixtures/dates/locales and stale contract projections. Do not treat an interrupted
security test as proof of isolation. Do not waive these failures to promote RC30.

Browser/UI, optional sibling-plugin distribution, backup/restore, reconciliation
with the actual newer environment source and deployment acceptance are outside
this isolated matrix. PR48/49/50/52 are not merged; no deployment is approved.

## Evidence and review

[Static CI 42584235457](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42584235457)
passed. Local PHP lint529/529, language/XML, source preservation, Corporate
provenance/version and workflow-policy checks passed. Independent source review
found no tenant/provider scope broadening; independent QA reconciled the MariaDB
JUnit leaf counts and report statuses. No failures or debugging were suppressed.

Raw artifacts are retained locally in `outputs/pr52-acceptance/`; GitHub retention
is 14 days. The final documentation-only commit does not change the tested code.

MariaDB artifact13659131163, ZIP SHA256
`cdd3fc04495215504106518c7bfee6471ac313c567dcd28d09f0c56f3b229f68`.
Percona artifact13658598675, ZIP SHA256
`120762e6b887891c9866b9eec71061b7f33b3dd872e64eced6f3ac0ff0f70775`.
Both hashes were checked after download. First-iteration evidence is also kept
locally; its run is42581416667. The compact JSON includes final counts, remaining
failure messages and identity comparisons for both engines.
