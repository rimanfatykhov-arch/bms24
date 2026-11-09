# PR57 — selective PR53/PR56 integration acceptance

Date:2026-11-09. BMS2026110903, Corporate2026110901/API1.26. Draft only.

Source: `80f454bf01a3a925ca302fb3e46cf64b4585386d`.
Tested synthetic PR merge tree: `3cd6b06af8c9c723f4e47d6021674f8496f789d7`.
Documentation-only follow-up does not change tested plugin or workflow files.

- [Draft PR57](https://github.com/rimanfatykhov-arch/bms24/pull/57), based on PR56.
- [Static/artifact CI PASS](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42596006149).
- [Full Moodle matrix](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42596006129).
- [Conflict map prepared before integration](PR53_PR56_CONFLICT_MAP.md).
- [Transfers, migration/version rationale and rollback](PR53_PR56_INTEGRATION.md).
- [Independent review scope and remaining environment boundaries](PR57_REVIEW_LIMITS.md).

## Runtime evidence

Disposable GitHub runners only. Moodle4.1.22 core
`df1157bb845eabedd31989d6a1a6e18f638ff890`, PHP8.1.34, PHPUnit9.5.28.
MariaDB10.11.19 and Percona8.0.46-37; actual server strings checked by harness.

Both engines completed. All23 JUnit files per engine have zero skipped tests;
suite counts/outcomes match exactly between engines. Overall FAIL/HOLD is expected
while the39 pre-existing shared BMS failures remain.

| Gate | MariaDB and Percona (same result) |
|---|---|
| Fresh plus8 historical upgrade origins |9 PASS |
| Exact fresh/upgrade schema parity |8 comparisons of183 tables PASS |
|12 synthetic records per scenario, repeat upgrade/data/schema |PASS |
| Field repair/partial schema |29 tests /3721 assertions PASS |
| Ordered index |9/103 PASS |
| Task/deviation two-company isolation |2/66 PASS |
| Review queue |9/99 PASS |
| Employee learning/courses |5/65 PASS |
| Profile / Role Intelligence / readiness / design |1/5 +4/16 +3/9 +3/13 PASS |
| Communications |23/85 PASS |
| Privacy / Corporate provider |6/37 +4/23 PASS |
| RC30 version/runtime / CLI / report comparison |12/118 +4/48 +15/32 PASS |
| New membership/document service-boundary tests |4/19 PASS |
| Full Corporate |150/962 PASS |
| Full BMS |598/6981;20 errors,19 failures,0 skipped |
| Existing company core / documents / employee sync focused suites |FAIL:2 failures /4 errors+1 failure /1 error |
| Release decision |HOLD |

Origins:1645, reconstructed1879, reconstructed0804,0890,0900,0901 and BOTH
exact0902 trees: PR53 `28a60d010d8c59fd301a2bf1b2df628e38fb79dc` and PR56
`1ced6b88e5933bb8d63aa1806411ac4d8a6a5443`. For standalone1879/0804, Corporate
is reconstructed from main1645, not copied from live stage2613. Each scenario
checks code/installed version, twelve storage records spanning two tenant UUIDs,
and repeated upgrade. Document revisions1/2 and competency evidence remain intact.
Storage preservation fixtures are explicitly distinct from ACL tests.

## Comparison with both parents

| Full suite | PR53 | PR56 | PR57 both engines |
|---|---|---|---|
| BMS tests/assertions |587/6813 |563/5496 |598/6981 |
| BMS errors/failures |37/29 |23/20 |20/19 |
| Corporate |full discovery fatal |150/962 PASS |150/962 PASS |

PR57's39 failing BMS identities exactly equal the intersection of the two
parents' failures. No novel failure or parent-exclusive regression remains.
Relative to PR56 all4 failing identities resolved; relative to PR53,26 same-name
failures resolved plus the old inbox identity expanded into two passing datasets.

Raw identity comparison retains one missing PR53 name:
`communication_center_service_test::test_employee_inbox_combines_unread_communication_and_due_task`.
It was already parameterized in PR56 into `future deadline` and `past deadline`;
both pass in PR57. This mapping is explicit, not a skipped/deleted test. No PR56
test identity is missing. Four new access cases are added. Independent QA checked
JUnit leaf totals/report consistency and this exact mapping on MariaDB.
Root verified the same leaf counts, failing identities, schema comparisons and
12 preserved fixtures in each of9 scenarios on Percona. Evidence artifacts:
MariaDB13664063310 and Percona13665290460. Machine-readable details including
all39 raw failure identities are in [pr57-integration-results.json](pr57-integration-results.json).

PR53 learning/queue/profile/readiness/design behavior is preserved by source hashes
and passing focused tests. PR56 Corporate, communications, privacy and RC30 fixes
are preserved by exact source lineage and passing focused/full Corporate suites.
These proofs do not turn the remaining39 shared failures into release acceptance.

## Artifact

CI artifact13664345203:1786 files, plugin-only `kbacompany/` root; no complete
Moodle/Corporate deployment bundle. ZIP/manifest/CRC/version checks pass. Repeated
Linux builds are byte-identical. Install ZIP SHA256:
`94633e6dd09a9af508a2715cf0911c910dd0e8c82771d1308a4eced369d2b86d`.
All archive members match the source after EOL normalization;19 historical text
files differ only in line endings,58 development files are excluded by packaging
policy, no substantive mismatch or unexpected exclusion. Cross-platform ZIP byte
identity is not claimed.

## Remaining blockers and safe next steps

1. Full BMS gate remains red:39 shared failures include documents, employee sync,
   company core, tasks, Battle Plans and legacy domain fixtures/contracts. Keep
   their raw messages in machine-readable evidence; diagnose service defects
   separately from obsolete fixture contracts in a follow-up source branch.
2. Corporate2613 installed on stage41 contains additional board/paging/diagnostic
   code. Numeric0901 is not semantic superset proof. Reconcile those features
   separately before proposing any stage replacement.
3. Browser/HTTP ACL, legacy/owned shell/theme combinations, complete sibling stack
   and approved backup/restore rehearsal remain unexecuted here. No DB engine
   policy was changed to hide strict RC30 warnings.
4. Keep PR53/56/57 draft and unmerged pending owner review and successful gates.
   No automatic merge, production/staging deployment or installed-version lowering.

Rollback is described in the integration record: abandon/revert only this source
branch to exact PR56; a future deployment needs separately approved matched code
and database backup restoration. No operational rollback is required by this work.
