# PR58 — document and employee acceptance blockers

Base: exact PR57 `1bfa75bf439e8cf45725c2c3bb69ca01b543fb01`.
Tested source: `e37bb2a0c8c767d93f9e9e0d7647fd98af037740`.
Synthetic CI merge tree: `2794aec30d0ef8c6d5d993ee4dc56dfd0fa5ea83`.
Branch: `fix/pr57-document-employee-blockers`. BMS2026110904; Corporate0901 unchanged.

## Diagnosis and scope

All39 prior failures are classified with first-failure evidence in
[PR58_FAILURE_TRIAGE.md](PR58_FAILURE_TRIAGE.md) and [JSON](pr58-failure-triage.json).
34 are fixture/contract mismatches; four document membership cases are repaired
by explicitly satisfying the existing canonical BMS policy. One hidden UI display
mode question remains outside this PR. This does not prove later execution paths
are defect-free and does not authorize blindly updating every old expectation.

Seven targeted prior failures: five document-service cases, one semantic document
case and one employee synchronization case. The document test previously created
only Corporate membership; its semantic counterpart used an admin as reviewer
without BMS membership. Neither qualifies as canonical reviewer enrollment.
The process-model snapshot fixture omitted mandatory supplier/customer links.
The phone fixture exceeded Moodle user.phone1 varchar(20), failing before sync
could run. Contract0.3.3 and document_reference read expectations were obsolete:
the actual document contract is0.3.4 and missing/inaccessible reads use
document_notavailable. Exact expectations remain; no catch-all assertion replaces them.

All lifecycle, effective revision, immutable history, acknowledgement, form record,
STP snapshot and semantic relation assertions are retained. Five additional cases
require rejection and unchanged document data for Corporate-only, inactive or
foreign BMS membership, suspended and deleted Moodle users. Employee assertions
still prove replacement of email-like legacy phone and ignoring email-like input;
explicit email-validity/length preconditions demonstrate the intended case is reached.

No domain authorization, service behavior, schema or business-data migration was
changed. Unique0904 identifies the new test-bearing artifact; a code-only savepoint
preserves478 monotonically increasing checkpoints. Source preservation gate retains
all parent hashes except an explicitly pinned reviewed employee fixture delta.
Corporate, training, queues and communication fixes remain unchanged.

## Validation

Local PHP540/540, RU/EN9094 keys, XML syntax, source preservation, workflow policy,
and schema CLI40 cases/371 assertions PASS. Independently reviewed security and
fixture lifecycle; no weakened assertions or runtime-policy changes identified.
Review found and corrected a missing fromPR57 schema-comparison entry before the
final tested commit. Human review remains required; agent review is not approval.

[Static CI42600654023](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42600654023): PASS.
[Moodle matrix42600654381](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42600654381): both engines complete, overall FAIL/HOLD for32 retained prior failures.
The earlier db56450 run was superseded by e37bb2a; use only final source evidence.

Full matrix retains all earlier scenarios and adds exactPR57/0903: fresh plus9
origins,9 fresh/upgrade schema comparisons,12 preserved synthetic two-tenant rows
per scenario and repeated upgrade. No skips/xfail or relaxed gates. Real service
ACL tests are separate from direct storage preservation fixtures.

| Gate | MariaDB10.11.19 and Percona8.0.46-37 (same results) |
|---|---|
| Fresh +9 upgrade origins, including exactPR57 |10 PASS |
|183-table fresh/upgrade comparisons |9 PASS |
|12 preserved fixtures per scenario and repeat upgrade |PASS |
| Documents (including five new negative reviewer cases) |10 tests/53 assertions PASS |
| Employee synchronization |11/57 PASS |
| Semantic document lifecycle and relations |4/29 PASS |
| Prior integrated membership/document access checks |4/19 PASS |
| Learning / review queue |5/65 +9/99 PASS |
| Communications / privacy / Corporate provider |23/85 +6/37 +4/23 PASS |
| RC30 runtime/CLI/comparison |31/198 PASS |
| Full Corporate |150/962 PASS |
| Full BMS |603/7042;14 errors,18 failures,0 skipped |
| Overall release gate |FAIL / HOLD |

Compared with PR57 BMS598/6981 (20 errors/19 failures), exactly seven targeted
failing identities are resolved: all five document tests, employee invalid-phone
sync and effective-document semantic endpoint. No new failing identity appears.
Five reviewer datasets are added; no parent test is removed. Remaining32 failures
are unchanged prior failures, not waived by the focused success.

All24 JUnit files per engine reconcile with leaf counts and report statuses; no
skips or missing parent identities. Independent QA verified Percona including all
scenario steps,9 schema comparisons and20 data/repeat verification logs; root
verified matching MariaDB counts, outcomes, identity delta and fixture counts.
Evidence artifacts: MariaDB13667635979, Percona13665733953. Full failure identities,
messages, source/run IDs and hashes: [pr58-acceptance-results.json](pr58-acceptance-results.json).
Documentation-only follow-up does not change tested plugin/workflow inputs.

These runtime results confirm the complete document review/approval/publication,
effective-revision/history, acknowledgement, form-record and STP snapshot paths
now run through their original assertions. No service permissiveness was added to
make them pass. The email-like-phone case now reaches sync instead of failing at
an impossible setup insert. This is acceptance-fixture repair, not a claim that
runtime document or employee defects were patched.

## Artifact and rollback

Artifact13665547832 contains verified BMS-only INSTALL ZIP1787 files, manifest and
SHA256SUMS. SHA256 `4891a42e384a8c0042129e7bac1dca546f9640754465062ce620d447cc586b85`.
All members match source after EOL normalization;19 historical text files have
newline-only differences,58 development files excluded by existing policy.
No cross-platform byte-identical claim. Corporate/full Moodle are not in this ZIP.

Source rollback: abandon/revert only this isolated branch to exactPR57. Do not
rewrite other PRs or lower installed version counters. No deployment occurred;
future operational rollback requires separately approved matching code/database
backup restoration. Production, staging and shared local Moodle DB were not accessed.

Full BMS failures, browser/HTTP ACL, Corporate2613 semantic reconciliation, sibling
stack and backup/restore remain release gates until independently satisfied.
No merge or deployment is authorized by this report.

Safe follow-up: address the remaining date/task/reviewer fixture prerequisites in
a separate focused branch, then review exact contract/navigation expectations
against intended behavior.31 remaining first failures are fixture/contract cases;
one manager-display hidden-field presentation expectation needs an explicit policy
choice. No cross-company data exposure is established by that failing assertion.
Do not replace expected values mechanically or weaken guards to make the suite green.
