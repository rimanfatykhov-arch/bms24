# PR60 — task membership, batch and deadline stabilization

**RC30 HOLD.** Scoped fixture repair and additional security regressions; no
runtime authorization or business-rule changes. No merge or deployment.

## Source and pre-fix reproduction

Base PR59 `2446347adf26ebb9e1520414a65c89e930dadba2`; unchanged runtime baseline
`6f5c3f9c3116ccc05dec77ac1ade493247d7c312`. Candidate `e5e5dd46d6563c3c38668505ff8a47821bdadda9`;
CI synthetic merge `e934f4659e890941799649fb2554b8bd03396f8a`. Branch
`fix/rc30-task-membership-batch-deadlines`; BMS2026110906 / Corporate2026110901.
The plugin release label remains1.1.0-rc29; RC30 is the acceptance target, not an
already accepted/published release.
Follow-up evidence commit changes documentation only.

Repeat baseline run42610777656 attempt2 executed unchanged source on both DBs:
610 tests/7095 assertions,6 errors+18 failures,0 skipped. Percona artifact13700817210
was read and all three exceptions reproduced BEFORE implementation branch/edits;
MariaDB artifact13701102947 later confirmed the same identities and counts.
Exact errors and requirements: [diagnosis](PR60_TASK_MEMBERSHIP_DIAGNOSIS.md),
pr60-baseline-mariadb.json and pr60-baseline-percona.json.

Reviewer-admin was not a company employee; two foreign tasks assigned an admin
without foreign-company employee membership. All failed in require_employee
before intended assertions. Fixes use an eligible reviewer and real foreign
employees. Admin management privilege remains distinct from employee eligibility.
No ADR changes the rule. Canonical and legacy membership may coexist under the
existing policy; revocation tests explicitly revoke the actual authoritative
source, not an unrelated membership flag. Changing fallback precedence needs ADR.

## Implementation and invariants

Two existing test files retain their original semantic assertions and add full
task/audit snapshots on stale revisions, generated reassignment and foreign UUID
requests. Mixed batch updates allowed work while skipping foreign work. Reviewer
acceptance is performed by the actual reviewer; worker acceptance is denied.
Deadlines retain global7/overdue2 and exact pagination ordering.

New task_membership_regression_test adds eight cases: native inactive/removed and
legacy removed membership without resetting the session; two nonmember participant
rejections; observer read without start/accept; authentic generated-template
provenance/idempotency; and Moodle capability plus company-grant revocation.
All denied mutations preserve task rows, UUID/revision and audit history.

Core, Corporate API, domain authorization, UI, finance, AI and tariffs unchanged.
Domain-class diff only updates diagnostic expected version.0906 is a code-only
savepoint, with480 monotonic checkpoints retained; no DDL or business-data rewrite.
Version/package/preservation gates stay strict. Acceptance retains all scenarios
and adds exact PR59/0905 plus three focused suites.

### Tested source delta

- `.github/workflows/bms24-ci.yml`
- `.github/workflows/pr48-moodle-acceptance.yml`
- `AGENTS.md`
- `docs/reconcile/PR60_TASK_MEMBERSHIP_DIAGNOSIS.md`
- `docs/reconcile/pr60-baseline-mariadb.json`
- `docs/reconcile/pr60-baseline-percona.json`
- `local/kbacompany/classes/local/task_rc30_acceptance_service.php`
- `local/kbacompany/cli/test_schema_parity_2026110890.php`
- `local/kbacompany/db/upgrade.php`
- `local/kbacompany/release/RELEASE_NOTES_RC29_build2026110906.md`
- `local/kbacompany/tests/ordered_index_2026110900_test.php`
- `local/kbacompany/tests/schema_parity_2026110890_test.php`
- `local/kbacompany/tests/task_batch_actions1842_test.php`
- `local/kbacompany/tests/task_deadlines1843_test.php`
- `local/kbacompany/tests/task_membership_regression_test.php`
- `local/kbacompany/version.php`
- `tools/check_pr53_pr56_integration.py`
- `tools/check_reconcile_1879.py`
- `tools/check_workflow_policy.py`
- `tools/dev_ai_gate.py`
- `tools/test_workflow_policy_1878.py`
- `tools/tests/moodle_acceptance.php`

Final evidence documentation is additional to this tested-source inventory and
does not alter plugin, workflow or tooling inputs.

## Validation

[Static CI42678997127](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42678997127): PASS.
[Moodle matrix42678996842](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42678996842): FAIL/HOLD for retained BMS failures.
Moodle4.1.22: tag pin b27f0816159a7555a0b3ad9ff2f8f8ef701a29fa resolves to
Core df1157bb845eabedd31989d6a1a6e18f638ff890; PHP8.1.34 / PHPUnit9.5.28.
Local static541/541 PHP files, XML,9094 RU/EN keys, source/workflow/migration gates
and schema CLI40/371 PASS. Initial local lint reported PHP absent from PATH;
rerun with explicit PATH passed. No failed gate was suppressed.

| Gate | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Fresh plus11 historical upgrades | PASS12 | PASS12 |
|183-table fresh/upgrade parity | PASS11 | PASS11 |
|12 two-tenant storage fixtures and repeated upgrade | PASS24 verification logs | PASS24 verification logs |
| `battle_plan_service_test` | PASS: 14/74; E0/F0/S0 | PASS: 14/74; E0/F0/S0 |
| `communication_center_service_test` | PASS: 5/26; E0/F0/S0 | PASS: 5/26; E0/F0/S0 |
| `communication_live_service_test` | PASS: 1/4; E0/F0/S0 | PASS: 1/4; E0/F0/S0 |
| `communication_service_test` | PASS: 17/55; E0/F0/S0 | PASS: 17/55; E0/F0/S0 |
| `company_core_test` | FAIL: 16/78; E0/F2/S0 | FAIL: 16/78; E0/F2/S0 |
| `company_employee_sync_service_test` | PASS: 11/57; E0/F0/S0 | PASS: 11/57; E0/F0/S0 |
| `competency_review_queue_test` | PASS: 9/99; E0/F0/S0 | PASS: 9/99; E0/F0/S0 |
| `design_service_test` | PASS: 3/13; E0/F0/S0 | PASS: 3/13; E0/F0/S0 |
| `deviation_service_test` | PASS: 11/25; E0/F0/S0 | PASS: 11/25; E0/F0/S0 |
| `document_service_test` | PASS: 10/53; E0/F0/S0 | PASS: 10/53; E0/F0/S0 |
| `employee_competency_profile_service_test` | PASS: 1/5; E0/F0/S0 | PASS: 1/5; E0/F0/S0 |
| `employee_development_courses_test` | PASS: 5/65; E0/F0/S0 | PASS: 5/65; E0/F0/S0 |
| `kbacorporate_impl_task_provider_test` | PASS: 4/23; E0/F0/S0 | PASS: 4/23; E0/F0/S0 |
| `local_kbacompany` | FAIL: 618/7245; E3/F18/S0 | FAIL: 618/7245; E3/F18/S0 |
| `local_kbacorporate` | PASS: 150/962; E0/F0/S0 | PASS: 150/962; E0/F0/S0 |
| `ordered_index_2026110900_test` | PASS: 9/103; E0/F0/S0 | PASS: 9/103; E0/F0/S0 |
| `pr48_tenant_isolation_test` | PASS: 2/66; E0/F0/S0 | PASS: 2/66; E0/F0/S0 |
| `privacy_provider_test` | PASS: 6/37; E0/F0/S0 | PASS: 6/37; E0/F0/S0 |
| `rc30_integrated_access_test` | PASS: 4/19; E0/F0/S0 | PASS: 4/19; E0/F0/S0 |
| `role_intelligence_service_test` | PASS: 4/16; E0/F0/S0 | PASS: 4/16; E0/F0/S0 |
| `role_readiness_view_test` | PASS: 3/9; E0/F0/S0 | PASS: 3/9; E0/F0/S0 |
| `schema_parity_2026110890_test` | PASS: 29/3721; E0/F0/S0 | PASS: 29/3721; E0/F0/S0 |
| `semantic_service_test` | PASS: 4/29; E0/F0/S0 | PASS: 4/29; E0/F0/S0 |
| `task_batch_actions1842_test` | PASS: 4/39; E0/F0/S0 | PASS: 4/39; E0/F0/S0 |
| `task_deadlines1843_test` | PASS: 3/37; E0/F0/S0 | PASS: 3/37; E0/F0/S0 |
| `task_membership_regression_test` | PASS: 8/110; E0/F0/S0 | PASS: 8/110; E0/F0/S0 |
| `task_rc30_acceptance1864_test` | PASS: 12/118; E0/F0/S0 | PASS: 12/118; E0/F0/S0 |
| `task_rc30_cli_response1866_test` | PASS: 4/48; E0/F0/S0 | PASS: 4/48; E0/F0/S0 |
| `task_rc30_report_comparison1867_test` | PASS: 15/32; E0/F0/S0 | PASS: 15/32; E0/F0/S0 |
| `task_service_test` | PASS: 12/48; E0/F0/S0 | PASS: 12/48; E0/F0/S0 |
| Browser/HTTP/session ACL, full sibling distribution, Corporate2613 reconciliation, operational backup/restore | NOT RUN | NOT RUN |
| Staging/production deployment | NOT RUN | NOT RUN |

Suite cells show tests/assertions and errors/failures/skipped. Full BMS changes
from610/7095 and24 nonpassing to618/7245,
3 errors+18 failures,0 skipped.
Exactly three prior failures resolve and eight new cases pass. No missing tests,
new failing identities, decreased per-test assertions, or changed remaining
failure type/headline across27 retained suites; all30 JUnit files reconcile with
driver statuses and leaf counts. This is identity/assertion evidence, not
instrumented code coverage. Three legacy bootstrap warnings remain unchanged.
Storage preservation fixtures are separate from actual service-level ACL tests.

### Resolved identities

- `local_kbacompany\task_batch_actions1842_test::test_batch_start_accept_and_stale_revisions`
- `local_kbacompany\task_batch_actions1842_test::test_generated_tasks_cannot_be_bulk_reassigned_and_cross_company_task_is_not_updated`
- `local_kbacompany\task_deadlines1843_test::test_bucket_filter_and_global_counts_are_separate_and_acl_scoped`

### Added identities

- `local_kbacompany\task_membership_regression_test::test_revocation_blocks_reads_batches_and_deadlines_without_new_session with data set "native inactive"`
- `local_kbacompany\task_membership_regression_test::test_revocation_blocks_reads_batches_and_deadlines_without_new_session with data set "native removed"`
- `local_kbacompany\task_membership_regression_test::test_revocation_blocks_reads_batches_and_deadlines_without_new_session with data set "legacy removed"`
- `local_kbacompany\task_membership_regression_test::test_nonmember_participant_cannot_be_assigned_even_by_admin with data set "assignee"`
- `local_kbacompany\task_membership_regression_test::test_nonmember_participant_cannot_be_assigned_even_by_admin with data set "reviewer"`
- `local_kbacompany\task_membership_regression_test::test_observer_visibility_does_not_allow_start_or_accept`
- `local_kbacompany\task_membership_regression_test::test_generated_task_retains_source_uuid_revision_and_audit_after_reassignment_denial`
- `local_kbacompany\task_membership_regression_test::test_company_grant_requires_moodle_capability_and_revocation_is_effective`

Raw artifact IDs MariaDB13702253363 and Percona13703404339; ZIP hashes,
runtime metadata, all IDs/messages and comparisons are in
[pr60-acceptance-results.json](pr60-acceptance-results.json). Prior learning/queue,
RC30 diagnostics, document/employee, schema and isolation checks remain exercised.

Initial architect/QA/security reviews confirmed fixture causes. Independent QA
reviewed the implemented patch and final raw MariaDB and Percona evidence, including cross-engine test identities, assertions, schemas and preservation logs. A temporary backend403
interrupted additional agent requests; the final security review was retried and
completed on e5e5dd4 with no new findings, including MariaDB focused runtime logs.
Remaining-failure classification was reviewed by the lead with independent QA
confirmation of the next Kanban scope. Human approval and operational acceptance
remain outstanding.

## Remaining failures and next priority

Primary grouping of the remaining21 identities (no duplicates):

| Group | Count |
|---|---|
| Battle Plans and tasks | 3 |
| Contracts and stale tests | 6 |
| BMS UI and App Shell | 7 |
| Metrics and finance | 1 |
| Processes and documents | 3 |
| Rights and company membership | 1 |

[Detailed exact-ID triage](pr60-remaining-triage.json) distinguishes first-failure
fixture/contract mismatches from the manager-display policy question. It does not
prove later execution paths are correct. No remaining test was waived.

Next bounded group: task_kanban1846_test::test_editor_and_columns_render_without_raw_html_or_missing_tasks
and task_kanban1850_test::test_page_scoped_grouping_and_full_counts_in_board_html.
Both use test-company and fictional actor10; strict company resolution correctly
fails before rendering. Independently reproduce, seed a real company/non-admin
member and correct assignee/creator IDs, retain can_manage=false, escaping,
grouping, counts/overlimit and permitted transitions. Do not change UUID validation
or ACL. QA independently confirmed this source diagnosis. No next PR created.
Manager-display normalization requires a separate explicit policy decision if
behavior changes. Event attempts0->1->1->2, process contracts and financial
arithmetic are separate future scopes; no mechanical blanket expectation updates.

## Artifact and rollback

Artifact13702016382: verified BMS-only INSTALL0906 ZIP1790 files, CRC/manifest/version
and SHA256 `05a4a65ffd896990ece324a157ee8b6e1ef011ee8e2459e3a3f1d20777e03986`. No substantive source
mismatch;19 historical text files differ only by CRLF/LF and58 development files
are excluded by unchanged policy. Corporate and full Moodle are not in this ZIP.

Source rollback: abandon/revert only this branch to exactPR59; do not rewrite
parent PRs or lower installed version counters. No deployment occurred. Future
operational rollback requires separately approved matching code/DB/files backups
and restoration rehearsal. No production/staging or shared local Moodle DB access.
Release HOLD pending remaining BMS failures and NOT RUN gates. Issue45 remains
the later infrastructure priority after baseline acceptance.
