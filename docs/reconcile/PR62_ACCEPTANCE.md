# PR62 — Kanban fixture stabilization

**RC30 HOLD.** Both raw matrices confirm the bounded fixture repair; this does not constitute release acceptance. The full BMS suite remains FAIL; no merge or deployment.

## Source and diagnosis

Base PR60 `a60ce4e99fe1931774eaa7da3be0e23fe1d0b92f`; tested candidate `acff5299f895badb3f5efbfc84b676ef8f7ef9fd`;
CI synthetic merge `32c06de0892adf3d210535c2432a11a0c1110bd3`. Branch `fix/rc30-kanban-fixtures`.
BMS2026110906 / Corporate2026110901, release label1.1.0-rc29 unchanged.
Follow-up documentation commit does not change tested inputs.

Recent unchanged-runtime baseline from run42678996842: 618 tests/7245 assertions,
3 errors+18 failures, zero skipped on both engines. The two exact Kanban errors
were independently checked in raw artifacts13702253363/13703404339 before edits;
no redundant baseline rerun is claimed. Exact identities and stacks:
[baseline](PR62_KANBAN_BASELINE.json), [diagnosis](PR62_KANBAN_DIAGNOSIS.md).

Both test models supplied invalid `test-company` and fictional actor10. Real
company resolution in the existing ACL correctly rejected these values. Fixtures
now seed a native company and real ordinary member, set the actual session/model
actor and1850 assignee/effective-assignee/creator. Assertions exclude site admin,
viewallcompanies and company manager privilege; can_manage remains false.

All original1846 seven and1850 six render assertions remain. Added assertions
verify positive escaped text, exact card cardinality, hidden board column while
retaining its preferences editor, full count5 versus rendered1, WIP5/1 and allowed
start route without accept. Independent QA caught the initially overbroad hidden
column assertion before CI; it now targets board sections only. No test skipped,
removed, or changed to bypass a domain guard.

Only two PHP test files, focused harness list, CI base-branch trigger and evidence
documents changed. No runtime, UI implementation, Core, Corporate, ACL, schema,
migration or business-rule edits. No ADR required. Render DTO tests do not prove
SQL visibility or HTTP authorization; prior service-level security checks remain.

## Actual matrix

[Static CI42686347343](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42686347343): PASS,
including PHP7.4 syntax. Local static541/541,9094RU/EN keys,XML,source preservation,
workflow policy and diff check PASS. Initial artifact download was blocked by the
local network sandbox; authorized read-only retry succeeded and verification ran.

[Moodle42686347318](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42686347318): FAIL
because retained BMS tests remain failing. Moodle4.1.22 / PHP8.1.34 / PHPUnit9.5.28.

| Gate | MariaDB10.11.19 | Percona8.0.46-37 |
|---|---|---|
| Fresh +11 historical upgrades | PASS12 | PASS12 |
|183-table fresh/upgrade parity | PASS11 | PASS11 |
| Data preservation / repeated upgrade | PASS24 logs,12fixtures each | PASS24 logs,12fixtures each |
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
| `local_kbacompany` | FAIL: 618/7274; E1/F18/S0 | FAIL: 618/7274; E1/F18/S0 |
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
| `task_kanban1846_test` | PASS: 5/51; E0/F0/S0 | PASS: 5/51; E0/F0/S0 |
| `task_kanban1850_test` | PASS: 5/39; E0/F0/S0 | PASS: 5/39; E0/F0/S0 |
| `task_membership_regression_test` | PASS: 8/110; E0/F0/S0 | PASS: 8/110; E0/F0/S0 |
| `task_rc30_acceptance1864_test` | PASS: 12/118; E0/F0/S0 | PASS: 12/118; E0/F0/S0 |
| `task_rc30_cli_response1866_test` | PASS: 4/48; E0/F0/S0 | PASS: 4/48; E0/F0/S0 |
| `task_rc30_report_comparison1867_test` | PASS: 15/32; E0/F0/S0 | PASS: 15/32; E0/F0/S0 |
| `task_service_test` | PASS: 12/48; E0/F0/S0 | PASS: 12/48; E0/F0/S0 |
| Browser/HTTP/session ACL, full sibling distribution, Corporate2613 reconciliation, operational backup/restore | NOT RUN | NOT RUN |
| Staging / production deployment | NOT RUN | NOT RUN |

Cells: tests/assertions, errors/failures/skipped. Candidate full BMS:
618/7274, 1 error + 18 failures,
0 skipped. Exactly two baseline errors resolve, zero new failing
IDs, zero missing cases, no decreased per-test assertions across30 retained suites.
All32JUnit files agree with driver status/counts. Remaining failure types and
headlines unchanged. Learning/queues, RC30, documents/employees, membership,
isolation, migration and Corporate checks retained. Legacy bootstrap warnings
unchanged. This is exact-ID/assertion evidence, not instrumented code coverage.

Resolved:
- `local_kbacompany\task_kanban1846_test::test_editor_and_columns_render_without_raw_html_or_missing_tasks`
- `local_kbacompany\task_kanban1850_test::test_page_scoped_grouping_and_full_counts_in_board_html`

Raw evidence IDs: MariaDB13706258209, Percona13706233666.
[Machine-readable comparison, runtime metadata and hashes](pr62-acceptance-results.json).
Independent source QA/security reviewed the scope. Final independent QA verified
both raw engine artifacts, cross-engine test identities/counts, all32 JUnit and
30 retained suites,183-table schema equality and24 preservation logs per engine.
No blocking review findings remain for documenting this bounded repair; human
approval and full release acceptance remain outstanding.

## Artifact identity and version decision

The existing packager includes PHP tests. Artifact13704536748
contains1790 files and passes CRC/manifest/version verification. Its SHA256 is
`afa36dead177ceb87b0a2833891823cc70980fed890992fcd04c2ebf5debd080`. Compared byte-for-byte with PR60 ZIP
`05a4a65ffd896990ece324a157ee8b6e1ef011ee8e2459e3a3f1d20777e03986`, exactly
`tests/task_kanban1846_test.php` and `tests/task_kanban1850_test.php` differ.
Every other entry, including all runtime files, is byte-identical.

Version 0906 is intentionally retained for this test-only follow-up, not a new
product release. The two ZIPs are NOT byte-interchangeable and must be identified
by run, Git SHA and hash; do not publish the PR62 ZIP as a new build0906 release.
No version savepoint or data migration was introduced.

## Remaining failures and next scope

19 failures remain, none waived:

- Battle Plans and tasks: 3
- Contracts and stale tests: 6
- BMS UI and App Shell: 5
- Metrics and finance: 1
- Processes and documents: 3
- Rights and company membership: 1

[Exact-ID triage](pr62-remaining-triage.json) preserves prior classifications;
first-failure diagnosis does not prove later paths. Independent architecture review
proposes three bounded contract-version assertions: battle_plan_management_overview
0.5.10->0.5.11, indicator_registry0.5.2->0.5.5.2, market_model0.3.5->0.4.11.
Keep explicit version literals and all semantic/schema-hash assertions; preserve
schema-neutral read models, canonical indicator ownership, company scope and no
CRM/ERP fact mutation. Do not replace expected values with production constants.
No business-rule change or ADR is proposed. Company/permission contracts remain
separate. No next PR or implementation started.
Manager-display policy remains separate and requires owner decision before a
business-rule change.

## Rollback and acceptance boundary

Abandon/revert only this test branch to exact PR60. No deployment occurred, so
there is no installed data/version rollback; never lower an installed counter.
Operational restore remains NOT RUN and requires separately approved matching
code/database/files backups. No shared Moodle DB, staging or production changes.
Human approval and full RC30 acceptance remain outstanding.
