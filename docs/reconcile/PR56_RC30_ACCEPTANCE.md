# PR56 — RC30 diagnostic repair accepted in isolation; release HOLD

Date:2026-11-09. Branch `fix/issue44-rc30-diagnostics`, draft PR56 over unmerged
PR52. BMS2026110902 / Corporate2026110901, RC29. No deployment or merge.

Tested source: `979a23e9c6f9cb4ea82ab5b7272be36e6bbfff9c`.
Synthetic PR merge tree: `e27f81db34da5b8cc609b0f5cf9702b4f17a062a`.
[Full matrix42589047888](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42589047888)
ran on Moodle4.1.22 (`df1157bb845eabedd31989d6a1a6e18f638ff890`), PHP8.1.34,
PHPUnit9.5.28, MariaDB10.11.19 and Percona8.0.46-37. Only disposable GitHub
Actions databases were modified; production/staging/shared local QA were untouched.

## Outcome

| Gate | MariaDB | Percona |
|---|---|---|
| Fresh install and1645/reconstructed1879/0890/0900/0901 upgrades | PASS | PASS |
| Five exact183-table schema comparisons, data/default preservation, repeat upgrade | PASS | PASS |
| Field repair |21 tests /2489 assertions PASS |same |
| Ordered index |8 tests /81 assertions PASS |same |
| Two-company task/deviation isolation |2 tests /66 assertions PASS |same |
| Communication |23 tests /85 assertions PASS |same |
| Privacy / Corporate provider |6/37 and4/23 PASS |same |
| RC30 version/runtime acceptance |12 tests /118 assertions PASS |same |
| RC30 exception/JSON safety |4 tests /48 assertions PASS |same |
| RC30 report comparison |15 tests /32 assertions PASS |same |
| Full Corporate |150 tests /962 assertions PASS |same |
| Full BMS |563 tests /5496 assertions;23 errors /20 failures |same |
| Overall release gate |**FAIL / HOLD** |**FAIL / HOLD** |

All13 JUnit files per engine have zero skipped tests. Compared with PR52, no new
failing identities appear; exactly the two targeted RC30 failures are resolved.
Independent QA reconciled Percona testcase/assertion counts with report.json;
root verified identical final suite totals/failing identities on MariaDB.
The machine-readable `pr56-rc30-results.json` retains all43 remaining BMS cases.

## What was fixed and what was preserved

Moodle's localized exception message no longer prevents classifying a missing
schema: the typed hint is inspected and only an allowlisted code is emitted.
Negative tests cover non-coding exceptions, message-only/embedded markers,
debug-info markers, non-string hints and private JSON output. The faulty
comparison fixture now uses a canonical error; warning and strict behavior are
separately asserted. Canonical severity cannot be relabeled by input JSON.

Actual DB tests verify exact/older/future build checks in both strict modes,
zero DB writes and unchanged configuration. Genuine engine warnings remain.
This does not approve MariaDB/Percona as replacements for the still-pinned
historical MySQL5.7 strict profile. Runtime-profile policy needs its own decision.

Only BMS gets the code-only0902 checkpoint. Corporate source is byte-identical
to PR52 and remains0901; no schema, ACL, membership or business-data migration
was added. Architectural/security review found no new policy or tenant-boundary
change requiring another ADR. Accepted ADR-0002 remains the source-line decision.

## Static and packaging evidence

[Static CI42589047887](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42589047887)
passed, including PHP7.4 syntax-only compatibility, PHP8.1 lint, dependency/source
preservation, XMLDB/language, policy, offline/symlink, CLI and packaging gates.
Local checks passed lint529/529, migration CLI299 assertions, version10 cases,
workflow12, CLI16 and comparison26 scenarios. The Windows offline symlink test
could not finish (WinError1314); the retained Linux CI test passed.

Verified CI INSTALL ZIP:1759 files, plugin-only (not a complete Moodle install).
SHA256: `c195b749a0d74605f220683fed9eab289e1dd8f633ce8e407cf83e0af4ae4c48`.
CI artifact13661000844 contains ZIP, manifest and SHA256SUMS; wrapper SHA256:
`55c4eec16d27c6f4a94b30ba23b75a65664d2b527ca4ce59061f85c91ec64449`.
Repeated Linux packaging is byte-identical and manifest validation passes.

Two local Windows packages also match each other, but have SHA256
`822e9c0a369517b23dd7ac9ad609bea228bbceddc4c0786ab1c13d868268e146`.
Comparison proves the same entry set with only CRLF/LF differences in24 files.
Use the exact CI artifact above as the review candidate. Cross-checkout newline
normalization is recorded for Issue45, not silently claimed solved by local
determinism. Neither artifact has deployment approval.

## Raw evidence and next step

MariaDB artifact13660942651 ZIP SHA256:
`78e4374d94fa41733ea37833496a580946e1c4c36650acce6fb342583cc3aee2`.
Percona artifact13660389268 ZIP SHA256:
`0793e4cd0eb0a5516f4e5d08b20ed54218c7b965c5bc490452c8f00a3bcff9c7`.
All downloaded hashes were checked. GitHub retention14 days; raw local evidence
is in `outputs/pr56-acceptance/`. The final report commit changes only docs/JSON;
tested runtime, tests and plugin packaging input remain source979a23e.

See [re-audit and integration order](ISSUE44_REAUDIT_RC30_DIAGNOSTICS.md).
Issue44 is not complete; Issue45 operational deployment stays blocked. Next small
increment should reproduce the remaining company/document authorization failures
and distinguish invalid membership fixtures from actual access defects. Do not
weaken normative assertions or mark interrupted security tests accepted. Browser,
optional sibling plugins, live-environment reconciliation and backup/restore are
NOT RUN here. Role Intelligence R1→R2 remains a reviewed-delta follow-up after
baseline stabilization. No PR is merged and no server is deployed.
