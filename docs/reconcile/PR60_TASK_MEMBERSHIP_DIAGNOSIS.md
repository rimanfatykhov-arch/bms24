# RC30 task membership, batch and deadlines — pre-fix diagnosis

Base PR59 head2446347adf26ebb9e1520414a65c89e930dadba2; runtime source
6f5c3f9c3116ccc05dec77ac1ade493247d7c312 (head differs only in documentation).
BMS0905, Corporate0901. Independent unchanged baseline run42610777656 attempt2
was started before edits. New Percona artifact13700817210 reproduced all three
exact exceptions before the implementation branch was created.

| Exact test | First failure and owning requirement |
|---|---|
| task_batch_actions1842_test::test_batch_start_accept_and_stale_revisions | taskcenter_employee; task_service validate_common reviewer check line550. Admin creator is not automatically a company employee; use the existing eligible reviewer and accept as that user. |
| task_batch_actions1842_test::test_generated_tasks_cannot_be_bulk_reassigned_and_cross_company_task_is_not_updated | taskcenter_employee; assignee check line546. Foreign-company task assigned to admin with no membership; create an actual foreign-company employee. |
| task_deadlines1843_test::test_bucket_filter_and_global_counts_are_separate_and_acl_scoped | Same invalid foreign assignee; setup fails before the count/filter assertions. Retain all exact counts and pagination expectations. |

Architectural, QA and security source reviews independently confirmed these
fixture contradictions. No authorization exemption, runtime business-rule change
or new ADR is required. Batch retains company-context and SQL projection checks,
action permissions, per-task lock/transaction/revision verification. Deadline
summary intentionally removes only the selected bucket filter, preserving scope.

Canonical membership and legacy Corporate membership can both provide access in
adopted companies under the existing policy. Tests must revoke the actual source;
changing this precedence would require an ADR. New revocation tests separate
native-only inactive/removed membership from legacy removal, using non-admins
without viewallcompanies and retaining stale task relationships and the session.

Original assertions remain. Add full task/audit comparisons for stale or foreign
requests, observer visibility without execution/acceptance, invalid participants,
real generated-template provenance, and Moodle capability/company grant denial.
Do not claim these service tests replace HTTP/session/browser acceptance.

Build0906 adds a code-only savepoint and exact0905 upgrade coverage. Corporate,
Core, schema definitions and domain authorization stay unchanged. Full dual-engine
results and remaining failure identities will be recorded after candidate CI.
Release HOLD; no merge or staging/production access.

Final dual-engine evidence: [PR60_ACCEPTANCE.md](PR60_ACCEPTANCE.md). Both unchanged-source baseline artifacts and final candidate raw JUnit were verified. Exactly three target errors resolve; eight new cases pass; no new or missing failure identities. RC30 remains HOLD for 21 prior failures.
