# Issue44 — current baseline re-audit and RC30 diagnostic repair

Date: 2026-11-09. Scope: source/isolated CI only. No servers, merges or deployments.

## Current evidence versus historical audit

The owner approved ADR-0002 on2026-11-08. Its integration anchor remains
`a36c5ab8ac129433eeddb0ae341717e35b614ac8` (`integration/rc30-baseline`).
PR55 is useful historical import evidence, not a replacement for this decision
or for subsequent real acceptance results. Starting another integration from
main would discard/repeat reviewed fixes; no such branch is created here.

| Source | SHA | BMS version | BMS XMLDB tables | Upgrade savepoints |
|---|---|---|---|---|
| main |740fce16db15bb3551071cc11fe584c680fd738d |2026101645 |136 |261 |
| PR8 import |eccc1eb221df90675067b1011e986702d96a4985 |2026101879 |142 |459 |
| PR52 |1146e1bc330621ffcfa7dcb3881a2188110904c8 |2026110901 |142 |462 |

Savepoints in these sources are unique and increasing. This does not alone prove
their DDL correctness. Actual PR52 fresh/upgrade evidence covers1645,
reconstructed1879,0890 and0900, with exact183-table plugin schema comparison on
MariaDB10.11.19 and Percona8.0.46-37. The extra tables belong to Corporate;
183 must not be confused with142 BMS tables.

`git diff --no-renames` main→import:2078 deleted,986 added,75 modified paths.
GitHub's rename-aware2729-file historical statistic uses a different count.
Main→PR52:0 deleted,1057 added,107 modified. The existing deletion inventory
and preservation gate retain main documentation/workflows/dependencies; the
original PR8 remains destructive if merged wholesale.

The import still pins EXPECTED_PLUGIN_VERSION2026101873 against plugin1879.
PR52 pins0901 consistently. The defect is historical in PR8, already repaired
in the continuing source line. Candidate0902 preserves strict identity and uses
a BMS-only code checkpoint; unchanged Corporate remains0901/API1.26.

PR52 complete Corporate150/962 and communication/privacy/provider/schema/tenant
gates passed on both engines. Full BMS558/5397 retained23 errors/22 failures.
See [PR52 evidence](PR52_RUNTIME_ACCEPTANCE.md); **Issue44 remains open/HOLD**.

## PR and integration order

Re-read GitHub: PR8,38,39,41,43,55 and staging35,36,54 are open/unmerged.
Main is unchanged. Product/governance sources were read directly at PR41
`9c758d154988564f09bbea979be4a46454d753ca` and PR43
`cd1cd314d74e7946354ba7ae6576cbef8636e56d`; accepted intent is distinct from
merge status. Existing candidate AGENTS/MASTER and boundary/QA/workflow docs
were also inspected. Governance does not authorize automatic deployment.

Preserve review order41→43, code42→46→48→49→50→52→this diagnostic repair.
These are dependencies for review, not a request to merge all outstanding PRs.
R1 PR38 (`aadc035435a80a8721ebc98b74ae623c7569906d`) then R2 PR39
(`092bcb2718a2466bf1b763fd5c9e83ab983e0591`) must be transplanted as reviewed
deltas after baseline gates, retaining current schema/version/languages/CI.
R2's older workflow edits must not replace the current acceptance workflow.
Infrastructure35→36→54 remains a separate preparatory line; no1899+ progression.
Issue45 operational deployment work waits for Issue44 acceptance.

## Small code increment and version policy

Two retained RC30 failures are independently reproduced/classified:

1. Moodle `coding_exception` wraps/localizes getMessage(), while the original
   hint is in `a`. The classifier now checks only a typed exception's string
   payload and fixed marker, returning an allowlisted code. No hint, SQL, path,
   table or UUID is output. Standalone CLI stubs now model Moodle's wrapper.
2. The error-regression test used orphan_task_history, canonically a warning.
   It now exercises orphan_checklists, an actual error. Separate tests preserve
   warning/non-strict, warning/strict and rejection of tampered severity.

No policy/ACL/schema changes are needed; architectural review confirms this
restores existing contracts under ADR-0002. No new ADR approval is required.
The accepted strict version policy remains: exact candidate version required,
older/future/unknown versions rejected; neither matching version nor passed
comparison authorizes promotion. New real-DB tests verify no config/data writes
and retain genuine environment warnings rather than pretending MariaDB/Percona
are the historical MySQL5.7 profile. Supporting a new strict runtime profile is
a separate explicit decision; tests on another engine are not certification.

## Dependencies, secrets and limitations

Corporate0901 stays byte-identical to PR52 and its817-file manifest. Moodle core
is unchanged. Optional sibling-plugin distribution/UI/backup-restore are not
certified by this matrix. No new dependencies are introduced.

Bounded tracked-file scanning found no config/env/private-key paths or obvious
token/private-key signatures in tracked text. This is not a secret-free
certification: legacy `local/bitrixsync_v5.0.31_PROD.zip.zip 17.08.2026` contains
499 archive entries; member names were checked but all content is not audited.
SSH inventory metadata contains route/config-path/fingerprint categories, not
private-key material. Packaging is plugin-scoped and does not certify all legacy
repository archives. Do not print suspected values into CI/report output.

## Validation

Results are recorded after executing this candidate; prior PR52 PASS does not
certify changed code. Required checks: syntax/static/language/XML, source and
dependency preservation, CLI regressions, deterministic install ZIP verification,
real Moodle focused gates and both full suites on MariaDB and Percona. Remaining
BMS failures stay blocking; no skip/xfail or severity relaxation is introduced.
