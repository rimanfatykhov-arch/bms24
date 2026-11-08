# PR48 full Moodle acceptance — FAIL / HOLD

Date: 2026-11-08. Candidate PR48: `e06cc96e2933e9142c1d51d2fcfa06b159737718`.
Test infrastructure: draft PR49, branch `test/pr48-moodle-acceptance`.
No production/staging connections, deployment or PR merge occurred in this task.
The tested application source is unchanged; only tests, runner and documentation
are added by PR49.

## Verified environment and scope

- Moodle 4.1.22, core commit `df1157bb845eabedd31989d6a1a6e18f638ff890`.
- PHP CLI 8.1.34, PHPUnit 9.5.28.
- MariaDB `10.11.19-MariaDB-ubu2204`.
- Percona `8.0.46-37`, GPL Release37 Revision `39e2b60e`.
- BMS `2026110890`; Corporate `2026102610`; stock Moodle plus these two plugins.
- Separate GitHub-hosted runners, pinned database image digests, new random
  databases per scenario, separate PHPUnit DB/dataroot, no deployment secrets.

Historical main1645 is installed from
`740fce16db15bb3551071cc11fe584c680fd738d`; imported1879 from
`eccc1eb221df90675067b1011e986702d96a4985`. The import deleted Corporate;
the 1879 scenario explicitly reconstructs it from main1645. It does not reproduce
production1878/Corporate.13 or stage41 build0802. No live data is used.

## Confirmed results

The initial completed matrix at test head `0cad57aa69a0d58650b9a431a893b5c7420b550f`
is [run42514684814](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42514684814).
Both engines produce the same acceptance decision:

| Gate | MariaDB | Percona |
|---|---|---|
| Actual Moodle fresh install | PASS | PASS |
| Actual CLI upgrade1645 → candidate | PASS | PASS |
| Actual CLI upgrade reconstructed1879 → candidate | PASS | PASS |
| Existing synthetic tenant data/defaults preserved | PASS | PASS |
| Repeat upgrade and schema stability | PASS | PASS |
| Moodle standard check_database_schema | PASS | PASS |
| Exact fresh vs upgrade1879 schema, 183 tables | PASS | PASS |
| Exact fresh vs upgrade1645 schema | **FAIL** | **FAIL** |
| Task/deviation cross-company access, two non-admin actors | PASS: 2 tests/66 assertions | PASS: 2 tests/66 assertions |
| Full plugin PHPUnit acceptance | **FAIL** | **FAIL** |

The initial BMS suite executed 540 tests /3397 assertions with54 errors and30
failures on each engine. The initial focused schema matrix executed13 tests /
620 assertions with13 errors. These counts include a test-fixture debugging
cascade and are superseded for test diagnostics by the final run below.

### Final diagnostic run

Test head: `147f40049b25e17a74b5d2032e631c0122b4453a`.
[Run42515754360](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42515754360)
checks the PR's synthetic merge tree `711163b8e99fd34d52ccdfbc7c00b1e8c40579c0`.
Application code remains PR48. Static CI run42515753335 passed.

Both engines completed: all install/upgrade/data-preservation results above reproduced.
The focused schema suite now executes13 tests /1257 assertions:0 errors,
11 failures from the real helper's XMLDB debugging warnings. Two cases pass.
The tenant suite again passes2 tests /66 assertions. The full BMS suite executes
540 tests /4034 assertions with40 errors and41 failures.

Corporate diagnostic fallback attempts all37 test files in separate processes.
MariaDB:27 PASS,10 FAIL; Percona:26 PASS,11 FAIL, including the unexecutable evidence file. The original full
suite remains FAIL; diagnostic per-file passes do not override its collection
failure. The36 produced JUnit files contain144 executed tests on each engine:
MariaDB666 assertions /29 errors /3 failures; Percona638 assertions /35 errors /
2 failures. The discovery-fatal file is not included in these counts.
Percona additionally fails `production_hardening_test.php` due to SQL syntax
around the unquoted `system` column in the ERP external-mapping query.

MariaDB evidence artifact13631791508:
SHA256 `316c8ab3f18b73bd330a7884ddb2cd128731e04468008f1011762656de505e1d`.
Percona evidence artifact13632085407:
SHA256 `8546ea57ee74b3e6f35d7a221ea5c2fb0d35a5786a013f5241e43f06583e5941`.
Artifacts are retained by GitHub for14 days; local copies were also saved under
the task workspace `outputs/pr48-acceptance/`.
Compact verified results and per-file statuses are preserved in
`pr48-moodle-acceptance-results.json` next to this report.

## Confirmed blockers and interpretation

### Ordered-index parity and a false positive gate

The only snapshot difference is the missing nonunique task index
`(companyuuid,status,duedate)` after upgrade1645. All fields and plugin versions
match. The older `due_ix(companyuuid,duedate,status)` remains present. Fresh
install has both indexes; reconstructed1879 upgrade matches fresh completely.

Checkpoint1863 in `local/kbacompany/db/upgrade.php` uses Moodle `index_exists()`.
In this core, `database_manager::find_index_name()` compares columns using
`array_diff`, ignoring order. Consequently it mistakes the existing due_ix for
the required status_due_ix. Standard schema validation and the current BMS
acceptance service reuse this check and incorrectly pass. The exact metadata
comparison in this harness deliberately preserves index column order.

Safe follow-up: a new reachable checkpoint, exact ordered columns and uniqueness
validation, preserve the existing due_ix, and independently validate the created
index. Simply calling `add_index()` after an exact precheck is insufficient:
that method internally repeats the order-insensitive existence check. Choose
and review a supported DDL generator/execution path, then rerun this matrix.
No index was changed by this acceptance task.

### XMLDB construction warnings in PR48

The actual repair helper constructs CHAR NOT NULL time fields with explicit
empty-string defaults. Moodle XMLDB emits debugging warnings and normalizes the
definition. The SQL upgrade and value preservation succeed, but strict Moodle
PHPUnit correctly rejects the warnings.

The first new test fixture made the same mistake. Its constructor was corrected
to use XMLDB `null` while retaining the expected SQL empty-string default. A
strict `assertDebuggingNotCalled()` in teardown now ensures a real helper warning
fails the test inside Moodle's cleanup path, avoiding secondary duplicate UUIDs.
Runtime warnings are not suppressed or declared expected; application code is
unchanged. Repair the helper through a separately reviewed source change.

### Broader suite blockers

- Communication tests:17 errors from `fullname()` because the SQL projection
  omits Moodle additional-name fields. Private-thread, author-only edit and
  foreign-thread UUID tests are among those interrupted; these boundaries are
  not established by the passing task/deviation tests.
- Corporate suite discovery: `implementation_evidence_test.php` accesses `$CFG`
  without declaring it global in PHPUnit's file-loader scope, causing a fatal
  `/filelib.php` require. The runner additionally attempts each test file in its
  own process after a collection fatal; this does not waive the failed suite.
- Corporate ERP diagnostic tests also expose a runtime TypeError: a missing
  mapping returns `false`, passed into a nullable `stdClass` contract in
  `erp_exchange.php` (including lines444/494/1034). Account opt-in, preservation
  of manual assignments and PII-safe snapshot cases are interrupted. Additional
  Corporate failures include17 fixture errors passing expressions by reference,
  three fixture/capability errors, a Point A score assertion and a privacy
  metadata-shape expectation. These must not all be classified as tenant leaks.
- Percona-specific ERP incompatibility: the query generated from conditions
  containing `system` fails with a SQL syntax error at `erp_exchange.php:1551`.
  In particular, `test_external_mapping_rejects_cross_company_target` aborts
  before establishing that boundary. MariaDB's corresponding file passes.
- Six privacy tests fail at the context-id membership assertion before deletion
  assertions. Integer/string context-id comparison is a plausible fixture cause,
  not yet a confirmed runtime diagnosis. Neither leakage nor successful
  anonymization can be claimed from these failures.
- RC30 report comparison expects `failed` for orphan task history while the
  implementation declares that check `warning` and the fixture uses non-strict
  mode. Resolve the normative severity/strict-mode contract; do not label this
  evidence a proven comparator bypass.
- Remaining failures include stale contracts, locale/URL expectations, invalid
  fixtures and an expected450/actual500 financial assertion. They require
  individual triage; they are not all established application vulnerabilities.

## Acceptance decision and safe order

**HOLD. Do not merge/deploy on the strength of green static CI or standard Moodle
schema checks.** Narrow task/deviation isolation passed; complete tenant/privacy/
communication/Corporate acceptance did not.

1. Correct XMLDB construction and add an exact-order index repair/gate without
   deleting the existing index; add focused regression tests.
2. Repair Corporate PHPUnit discovery, ERP missing-mapping handling and its
   Percona-incompatible mapping query, and
   communication user-name projection;
   separate stale fixture expectations from product defects.
3. Resolve privacy context typing and RC30 severity contract, then repeat both
   full engine matrices, including historical upgrades and tenant tests.
4. Independently reconcile actual stage0802/Corporate.13 source before planning
   any deployment. Candidate version0890 is not permission to overwrite newer
   feature trees or downgrade Corporate.

Browser/UI, all optional sibling plugins, backup/restore, real deployment and
live1878/0802 upgrades were not executed. They remain separate gates. Production
and staging remain untouched; no PR has been merged.
