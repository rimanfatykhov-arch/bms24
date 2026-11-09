# PR59 — RC30 P0 period/task/Battle Plan stabilization

RC30 remains **HOLD**. This is a bounded acceptance-fixture repair; it does not
claim production runtime defects were patched or authorize merge/deployment.

## Source and independent reproduction

- Base PR58: `d3a16c3d08b9b10ac7edc32658157aa7f237e9a0`.
- PR58 runtime baseline: `e37bb2a0c8c767d93f9e9e0d7647fd98af037740`; BMS0904/Corporate0901.
- Candidate source: `6f5c3f9c3116ccc05dec77ac1ade493247d7c312`; runtime patch `e15ea8b5b830dfc254959dfadd86f79813d438ea`.
- Synthetic CI merge: `0d9199d00b52b4e47e008c138df9b0a1fbf5f61e`. Branch `fix/rc30-period-task-battleplan`.
- BMS2026110905, Corporate2026110901 unchanged. Follow-up evidence commits only change documentation.

Before fixture edits, independent baseline run42600654381 attempt2 was started on
unchanged source; MariaDB completed and reproduced all eight target failures before
edits. Percona completed independently on the same unchanged source and confirmed
the same eight. Both raw JUnit files were independently reviewed:603 tests,
7042 assertions,14 errors+18 failures,0 skipped. Artifacts13669416723/13670091491
are attempt2, distinct from the earlier PR58 report artifacts.

Owning service requirements and first-error evidence are in
[PR59_P0_DIAGNOSIS.md](PR59_P0_DIAGNOSIS.md) and baseline JSON files. Display
`userdate()` stripped day zeroes, overdue fixtures contradicted their start date,
and Battle Plan fixtures omitted required work/reviewer acceptance. Existing
services already implement the required rules; no business-rule ADR is needed.

## Scope and safety

The three test files now use ISO dates and consistent acceptance timestamps,
valid optional starts with the employee's yesterday, and complete the original
review/accept lifecycle for both plan tasks. Original assertions are retained.
New cases prove strict date/calendar/time ordering and unchanged history after
chronology rejection. Existing methods additionally prove empty-plan and early
closure rejection, rejection of assignee acceptance, preservation of approval
metadata, and closed-plan rejection without task mutation.

Moodle Core, Corporate authorization and BMS domain policies are unchanged.
Only the RC30 expected version constant changes in domain-class files.0905 is
a unique test-bearing artifact identifier with a code-only savepoint; no DDL or
data rewrite. All479 checkpoints, published history, parent source checks and
previous acceptance gates remain. The matrix adds exactPR58/0904 as an upgrade
origin and three focused suites. No tests are deleted, skipped, or waived.

## Actual acceptance

[Static CI42610777658](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42610777658): **PASS**.
[Moodle matrix42610777656](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42610777656): **FAIL/HOLD** for retained BMS failures.
An earlier static run failed only on inherited PR58 report EOF whitespace; the
formatting was fixed and all gates rerun. The earlier matrix was superseded;
only the final source/run above is used for acceptance.

Disposable GitHub-hosted Moodle4.1.22, annotated tag pin
`b27f0816159a7555a0b3ad9ff2f8f8ef701a29fa` resolving to runtime Core commit
`df1157bb845eabedd31989d6a1a6e18f638ff890`, PHP8.1.34, PHPUnit9.5.28, MariaDB10.11.19 and
Percona8.0.46-37. Core and DB image pins are unchanged. No shared local DB,
production or staging access. Local540/540 PHP lint, RU/EN9094 keys, XML,
preservation/workflow/upgrade checks and schema CLI40/371 PASS; CI also checks
PHP7.4 syntax, full static gates, JS/shell and deterministic packaging.

| Gate | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Fresh plus10 historical upgrade origins | PASS,11 scenarios | PASS,11 scenarios |
| Fresh/upgrade schema parity | PASS,10 comparisons of183 tables | PASS,10 comparisons of183 tables |
|12 synthetic two-tenant storage fixtures and repeat upgrade per scenario | PASS | PASS |
| `battle_plan_service_test` | PASS: 14 tests / 74 assertions; 0 errors, 0 failures, 0 skipped | PASS: 14 tests / 74 assertions; 0 errors, 0 failures, 0 skipped |
| `communication_center_service_test` | PASS: 5 tests / 26 assertions; 0 errors, 0 failures, 0 skipped | PASS: 5 tests / 26 assertions; 0 errors, 0 failures, 0 skipped |
| `communication_live_service_test` | PASS: 1 tests / 4 assertions; 0 errors, 0 failures, 0 skipped | PASS: 1 tests / 4 assertions; 0 errors, 0 failures, 0 skipped |
| `communication_service_test` | PASS: 17 tests / 55 assertions; 0 errors, 0 failures, 0 skipped | PASS: 17 tests / 55 assertions; 0 errors, 0 failures, 0 skipped |
| `company_core_test` | FAIL: 16 tests / 78 assertions; 0 errors, 2 failures, 0 skipped | FAIL: 16 tests / 78 assertions; 0 errors, 2 failures, 0 skipped |
| `company_employee_sync_service_test` | PASS: 11 tests / 57 assertions; 0 errors, 0 failures, 0 skipped | PASS: 11 tests / 57 assertions; 0 errors, 0 failures, 0 skipped |
| `competency_review_queue_test` | PASS: 9 tests / 99 assertions; 0 errors, 0 failures, 0 skipped | PASS: 9 tests / 99 assertions; 0 errors, 0 failures, 0 skipped |
| `design_service_test` | PASS: 3 tests / 13 assertions; 0 errors, 0 failures, 0 skipped | PASS: 3 tests / 13 assertions; 0 errors, 0 failures, 0 skipped |
| `deviation_service_test` | PASS: 11 tests / 25 assertions; 0 errors, 0 failures, 0 skipped | PASS: 11 tests / 25 assertions; 0 errors, 0 failures, 0 skipped |
| `document_service_test` | PASS: 10 tests / 53 assertions; 0 errors, 0 failures, 0 skipped | PASS: 10 tests / 53 assertions; 0 errors, 0 failures, 0 skipped |
| `employee_competency_profile_service_test` | PASS: 1 tests / 5 assertions; 0 errors, 0 failures, 0 skipped | PASS: 1 tests / 5 assertions; 0 errors, 0 failures, 0 skipped |
| `employee_development_courses_test` | PASS: 5 tests / 65 assertions; 0 errors, 0 failures, 0 skipped | PASS: 5 tests / 65 assertions; 0 errors, 0 failures, 0 skipped |
| `kbacorporate_impl_task_provider_test` | PASS: 4 tests / 23 assertions; 0 errors, 0 failures, 0 skipped | PASS: 4 tests / 23 assertions; 0 errors, 0 failures, 0 skipped |
| `local_kbacompany` | FAIL: 610 tests / 7095 assertions; 6 errors, 18 failures, 0 skipped | FAIL: 610 tests / 7095 assertions; 6 errors, 18 failures, 0 skipped |
| `local_kbacorporate` | PASS: 150 tests / 962 assertions; 0 errors, 0 failures, 0 skipped | PASS: 150 tests / 962 assertions; 0 errors, 0 failures, 0 skipped |
| `ordered_index_2026110900_test` | PASS: 9 tests / 103 assertions; 0 errors, 0 failures, 0 skipped | PASS: 9 tests / 103 assertions; 0 errors, 0 failures, 0 skipped |
| `pr48_tenant_isolation_test` | PASS: 2 tests / 66 assertions; 0 errors, 0 failures, 0 skipped | PASS: 2 tests / 66 assertions; 0 errors, 0 failures, 0 skipped |
| `privacy_provider_test` | PASS: 6 tests / 37 assertions; 0 errors, 0 failures, 0 skipped | PASS: 6 tests / 37 assertions; 0 errors, 0 failures, 0 skipped |
| `rc30_integrated_access_test` | PASS: 4 tests / 19 assertions; 0 errors, 0 failures, 0 skipped | PASS: 4 tests / 19 assertions; 0 errors, 0 failures, 0 skipped |
| `role_intelligence_service_test` | PASS: 4 tests / 16 assertions; 0 errors, 0 failures, 0 skipped | PASS: 4 tests / 16 assertions; 0 errors, 0 failures, 0 skipped |
| `role_readiness_view_test` | PASS: 3 tests / 9 assertions; 0 errors, 0 failures, 0 skipped | PASS: 3 tests / 9 assertions; 0 errors, 0 failures, 0 skipped |
| `schema_parity_2026110890_test` | PASS: 29 tests / 3721 assertions; 0 errors, 0 failures, 0 skipped | PASS: 29 tests / 3721 assertions; 0 errors, 0 failures, 0 skipped |
| `semantic_service_test` | PASS: 4 tests / 29 assertions; 0 errors, 0 failures, 0 skipped | PASS: 4 tests / 29 assertions; 0 errors, 0 failures, 0 skipped |
| `task_rc30_acceptance1864_test` | PASS: 12 tests / 118 assertions; 0 errors, 0 failures, 0 skipped | PASS: 12 tests / 118 assertions; 0 errors, 0 failures, 0 skipped |
| `task_rc30_cli_response1866_test` | PASS: 4 tests / 48 assertions; 0 errors, 0 failures, 0 skipped | PASS: 4 tests / 48 assertions; 0 errors, 0 failures, 0 skipped |
| `task_rc30_report_comparison1867_test` | PASS: 15 tests / 32 assertions; 0 errors, 0 failures, 0 skipped | PASS: 15 tests / 32 assertions; 0 errors, 0 failures, 0 skipped |
| `task_service_test` | PASS: 12 tests / 48 assertions; 0 errors, 0 failures, 0 skipped | PASS: 12 tests / 48 assertions; 0 errors, 0 failures, 0 skipped |
| Browser/HTTP ACL, sibling-plugin distribution, Corporate2613 reconciliation, operational backup/restore | NOT RUN | NOT RUN |
| Staging/production deployment | NOT RUN | NOT RUN |

All27 JUnit files per engine reconcile leaf counts with suite summaries and the
driver statuses. Full BMS changes from603/7042 (32 nonpassing) to
610/7095 (6 errors+
18 failures),0 skipped. Exactly eight original failing
identities resolve, zero new failing identities, zero missing parent tests and
seven added dataset/method identities. The failing focused company_core suite
is retained baseline debt, not hidden by targeted success. Storage fixture
preservation is separate from actual service-level company isolation checks.
Three legacy PHP `MOODLE_INTERNAL already defined` bootstrap warnings also
match baseline; they were not suppressed or repaired in this bounded scope.

Independent QA re-read both engines' raw JUnit, all scenario steps, the actual
183-table schema JSON and all22 preservation/repeat logs. Both engines have the
same test identities and counts. Across all24 retained suites there are no
decreased per-test assertion counts; remaining failing identities retain their
outcome type and error headline. This is not instrumented code-coverage proof.
Security review verified the final source boundary and retained rejection checks;
these reviews do not substitute for owner acceptance.

### Resolved exact identities

- `local_kbacompany\battle_plan_service_test::test_active_plan_accepts_scoped_manual_task_but_closed_plan_rejects_it`
- `local_kbacompany\battle_plan_service_test::test_battle_plan_references_one_canonical_task_and_closes_after_execution`
- `local_kbacompany\deviation_service_test::test_accepted_task_does_not_close_deviation`
- `local_kbacompany\deviation_service_test::test_lowered_norm_cannot_be_used_as_improvement`
- `local_kbacompany\deviation_service_test::test_recheck_and_explicit_closure_do_not_create_statistics`
- `local_kbacompany\deviation_service_test::test_regular_user_cannot_read_deviations`
- `local_kbacompany\task_service_test::test_workspace_and_tasks_page_agree_on_open_and_overdue_tasks`
- `local_kbacompany\task_service_test::test_workspace_open_tasks_remain_visible_after_completed_history`

### Added identities

- `local_kbacompany\deviation_service_test::test_machine_observation_dates_preserve_single_digit_days`
- `local_kbacompany\deviation_service_test::test_invalid_observation_period_is_still_rejected with data set "unpadded observation"`
- `local_kbacompany\deviation_service_test::test_invalid_observation_period_is_still_rejected with data set "invalid calendar date"`
- `local_kbacompany\deviation_service_test::test_invalid_observation_period_is_still_rejected with data set "monthly start must be first"`
- `local_kbacompany\deviation_service_test::test_recheck_before_task_acceptance_preserves_deviation_and_check_history`
- `local_kbacompany\task_service_test::test_invalid_date_order_cannot_create_task with data set "due before start"`
- `local_kbacompany\task_service_test::test_invalid_date_order_cannot_create_task with data set "same day reversed time"`

All remaining identities, messages, raw artifact hashes, runtime counts and
scenario steps are recorded in [pr59-acceptance-results.json](pr59-acceptance-results.json).
Artifacts: MariaDB13671821934, Percona13672810860. Training/queues,
RC30 diagnostics, documents, membership, employee synchronization, tenant checks,
schema/index tests and full Corporate remain exercised without source changes.
No overall green-release claim follows from removal of these eight failures.

## Artifact and rollback

Artifact13669259457: BMS-only INSTALL0905 ZIP1788 files, verified CRC, manifest,
version and SHA256 `76b34d8dba9ac0b9cd04625b3fdbdd36e8e5b65fe3e7c437e1f1b0ae2dd68208`. Source comparison
finds no substantive mismatch:19 historical files differ only by CRLF/LF;
58 development files are excluded under existing packaging policy. Corporate
and full Moodle are not included. Cross-platform byte identity is not claimed.

Source rollback: abandon/revert this isolated branch to exactPR58; do not rewrite
parent PRs or lower installed version counters. No deployment occurred. Any future
operational rollback requires separately approved restoration of matching code,
database and file storage backups; this run did not test operational restore.

## Remaining blockers and next priority

Release HOLD: remaining BMS failures, human review, Corporate2613 semantic
reconciliation and the NOT RUN operational/distribution gates above. Independent
QA/security review is evidence, not owner acceptance.

Next bounded scope: `task_batch_actions1842_test` (two failures) and
`task_deadlines1843_test` (one failure). Independently reproduce and verify eligible
reviewer/foreign-company employee setup before editing; retain stale revision,
generated-task reassignment denial and cross-company read/write/count isolation.
Their current first errors occur during membership validation before intended
assertions. Later paths remain unproven; do not mechanically relax membership
guards. The separate hidden manager-display policy question requires an owner
decision if a business-rule change is proposed. No merge or deployment.
