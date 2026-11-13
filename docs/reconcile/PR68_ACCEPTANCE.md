# PR68 — non-manager workspace state and access regression

Issue #44. Draft; RC30 HOLD. No production/staging access or branch merge.

## Confirmed problem and bounded correction

PR67 base: `34763fd4b8980d36bf6de0a309a7737693e9e3dc`.
Candidate source: `303f47d1ad4acafc0286746ea1700d47b35997c4`.

The original `task_manager_workspace1853_test::test_non_manager_never_receives_manager_workspace`
passes the no-workspace/no-metrics assertions and fails the third assertion because
the HTML contains `display=manager`. Both PR67 full JUnit artifacts reproduce this.
`task_ui_service::listing()` previously built scope links/forms before its late
non-manager guard. This is a confirmed presentation-state defect, not proof of access
to company-wide task data.

The actual `tasks.php` route resolves company context, checks the current actor and
normalizes manager display to list before querying. `task_service::list_page()` and
`execution_report()` independently require company context and share company-scoped
SQL predicates; `scope=all` is normalized to personal roles for non-managers. Existing
explicit company task-edit grants count as management authority and must remain valid.

The renderer now normalizes denied manager display/scope before generating HTML,
matching the route. Its late guard is retained. This does not authorize data or make
an untrusted model safe: the model must still come from permission-scoped services.
No service ACL, Corporate authorization, role semantics or database definition changes.
No architecture change is required for aligning the renderer with existing policy.

## Regression coverage

Original tests/assertions remain. New owned/legacy cases check forbidden manager URL
and form state, company-wide scope, workspace/metrics and report-only sentinel UUIDs,
while requiring ordinary list/roles navigation. New real-service tests use two native
companies, ordinary non-admin users and no legacy mapping/global visibility:

- Crafted manager/all filters return only personally permitted task IDs and totals,
  assignee/project aggregates and risks; a foreign company read is denied.
- An explicit company task-edit grant permits the existing manager projection within
  that company only. Assignment/grant/role revocation takes effect in the same session.
- Inactive/removed membership rejects list/report despite residual grant and task
  participation. No permission cache is cleared to make revocation tests pass.
- Read/denied-read checks preserve task rows and task audit snapshots.
- Actual scoped service models are rendered and checked: a permitted manager sees
  the company workspace; after revocation, only the permitted personal task remains.

Fixtures with inactive native membership intentionally have no fallback legacy membership.
Company archival semantics are not changed. Existing department/business-role exceptions
are neither removed nor broadened. Company scope is tested at the service/query boundary;
renderer declarations are not substituted for data-access checks.

CI additionally swaps only the disposable renderer to the pinned PR67 version, executes
the unchanged original non-manager test and requires the expected single assertion failure.
It then restores candidate bytes in `finally`, verifies their SHA256, and runs every
focused/full suite. Baseline red is separate evidence, never a passing candidate test.

## Version and schema

BMS2026111002 / Corporate2026110901. Build1002 distinguishes changed runtime from PR67.
The last schema-changing checkpoint remains1001. A version-only1002 savepoint satisfies
the repository's upgrade-sequence gate without DDL. Published migration bodies and
install.xml remain unchanged. Diagnostic and artifact gates expect1002.
Actual CLI upgrade from exact PR67/1001 is added alongside all13 previous scenarios.
Two existing upgrade tests and the standalone parity double retain their assertions
and now expect the terminal version1002. Their schema and data checks are unchanged.
The first source2040a31 correctly failed the strict upgrade gate for a missing1002
savepoint; it is superseded, not accepted. Final-source results must be used below.

## Matrix

[Full matrix42794988475](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42794988475)
completed. Artifacts13754743773 (MariaDB) and13756950203 (Percona) were downloaded and
compared with PR67 by exact class/test/dataset identity. Independent QA verified both.
[Raw-result counts, exact IDs, exception text, provenance and hashes](PR68_EVIDENCE.json).

| Check | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Pinned renderer reproduction | PASS: expected1 assertion failure | same |
| Manager UI focused | PASS:4 tests /33 assertions | same |
| Access/revocation focused | PASS:6 tests /211 assertions | same |
| Execution analytics focused | PASS:7 tests /24 assertions | same |
| Full BMS | FAIL:626 tests /7523 assertions /1 error +14 failures /0 skips | same |
| Full Corporate | PASS:150 tests /962 assertions /0 skips | same |
|14 fresh/historical upgrade scenarios | PASS | PASS |
|13 schema comparisons,183 tables | PASS | PASS |
| New failures / missing prior IDs / assertion decreases |0 /0 /0 |0 /0 /0 |
| Browser/HTTP/deployed environments | NOT RUN | NOT RUN |

PR67 baseline:618/7292,E1/F15/S0. Exactly one prior failing identity now passes:
`local_kbacompany\task_manager_workspace1853_test::test_non_manager_never_receives_manager_workspace`.
Eight new cases pass. All35 previous JUnit suites remain;38 total after three added
focused invocations. Unique-ID and leaf/header count checks pass. The15 remaining
unsuccessful identities, exception types and messages match PR67; only generated
synthetic HTML sesskey values are narrowly normalized. No tests were deleted/skipped
and no existing per-test assertion count decreased.

Learning, diagnostics, documents, employees/membership, company isolation and migrations
retain their previous results and checks. Existing company_core contract failures are
not waived. Remaining failures are listed exactly in the evidence JSON; their prior
observations/hypotheses are in [PR67 triage](PR67_FAILURE_TRIAGE.md). They include Word
export, trend projection, navigation/shell/company contracts, finance/outbox, OFS/process
contracts and locale-sensitive task-role rendering. Neither source review nor this
bounded acceptance establishes general HTTP/browser security or a deployable release.

No unauthorized company-wide data projection was observed in the new non-manager,
foreign-company and revocation scenarios. Confirmed runtime correction is the renderer
state defect. Both full workflow jobs correctly remain FAILURE because15 other BMS
tests are unsuccessful. RC30 stays HOLD.

## Static and specialized evidence

[Final static CI42794988462](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42794988462)
passes all gates and retained PHP7.4 syntax. Local QA543 PHP files /9094 RU-EN keys /
XML PASS; upgrade sequence and standalone40-state parity double371 assertions PASS.

[Final repair CI42794988159](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42794988159)
passes on both engines. Artifacts13753279074 (MariaDB) and13751779886 (Percona) bind to
synthetic merge `d62018779285bd2eae8f14a6da5e6eaaa6c4833d`, PHP8.1.34. All13 fields,
30 rejection cases,110 retained rows, fractional/time controls and partial-DDL retry
pass. The injected partial failure stays at0906, before1001 and1002; successful CLI
upgrade reaches1002. This does not imply atomic rollback of historical DDL.

Static artifact13751869344:1794 entries, SHA256
`7a2073678328bfd08ba0dde9bf6215ad6c6706056019ef482d393619797bc303`.
Compared with PR67: zero removals; added regression test/release notes; changed renderer,
plugin/diagnostic version, appended upgrade savepoint and four test files. Every other
ZIP entry is byte-identical. Deterministic rebuild/manifest checks passed in CI.

## Limits and rollback

Read-only source review is not an HTTP/session/browser penetration test. Actual browser
login/request acceptance, real Corporate2613/deployed stack, production data and operational
backup/restore are NOT RUN. Tests run only in disposable CI databases.

Before merge/deployment, close the Draft PR or revert its complete source diff against
PR67 on an isolated branch (not only the last test commit). No deployed data needs rollback.
This is not approval to downgrade a
future installed Moodle version; any such recovery requires a separately approved
code/database backup plan. Do not revert PR66 data repair for this renderer correction.

Remaining BMS failures and operational gates continue to block RC30 release. Proposed
next bounded investigation: Word export incomplete fixture/locale assertions, retaining
OOXML integrity and numeric/date semantics. No next change is implemented by this PR.
