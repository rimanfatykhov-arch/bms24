# PR50 — two schema P0 fixes verified; full acceptance remains HOLD

Date:2026-11-09. Branch `fix/pr49-schema-p0`, draft PR50 over unmerged PR49.
Candidate BMS2026110900 / Corporate2026102610. Tested source head
`1b44f45b35ec8710f412a496424d84b0f196adf5`; GitHub PR merge tree
`8a48d19c95b25bec7e3925d9200b9790ca0139ca`.

[Full matrix42559953524](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42559953524)
completed on Moodle4.1.22 core `df1157bb845eabedd31989d6a1a6e18f638ff890`,
PHP8.1.34, MariaDB10.11.19 and Percona8.0.46-37. Both runners and every database
were disposable. Production, staging and shared local Moodle QA were untouched.

## What changed

- 0890 field helper now passes XMLDB `null` for an empty CHAR default while
continuing to validate actual SQL DEFAULT ''. No business values are rewritten.
- New reachable0900 checkpoint revalidates the three fields and adds the missing
ordered nonunique task index without deleting or renaming the existing due_ix.
Fixed-name conflicts fail closed; post-DDL metadata and old definitions are checked.
- Read-only acceptance now checks ordered columns and explicit nonuniqueness for
all four required indexes, eliminating the false positive from Moodle index_exists.

The DDL design, limitations and retry behavior are recorded in ADR-0003.

## Actual results on both engines

| Gate | MariaDB | Percona |
|---|---|---|
| Fresh Moodle installation | PASS | PASS |
| CLI upgrade1645, reconstructed1879, PR48 build0890 →0900 | PASS | PASS |
| Exact fresh/upgrade schema comparison, all183 plugin tables | PASS for all3 origins | PASS for all3 origins |
| Synthetic tenant data/default preservation; repeated upgrade | PASS | PASS |
| Field repair, partial states, incompatible definitions; no debugging |21 tests /2489 assertions PASS |21 tests /2489 assertions PASS |
| Ordered index, uniqueness, collision, preserved due_ix, replay/savepoint |8 tests /81 assertions PASS |8 tests /81 assertions PASS |
| Two-company non-admin task/deviation access |2 tests /66 assertions PASS |2 tests /66 assertions PASS |
| Full BMS PHPUnit |556 tests /5347 assertions,40 errors /30 failures |same |
| Corporate diagnostic files |27 PASS /10 FAIL |26 PASS /11 FAIL |
| Overall release acceptance |**FAIL / HOLD** |**FAIL / HOLD** |

All schema snapshots report BMS2026110900 and Corporate2026102610. The1879
scenario reconstructs its deleted Corporate dependency from main; it is not a
copy of production1878/Corporate.13. The0890 scenario uses exact PR48 commit
`e06cc96e2933e9142c1d51d2fcfa06b159737718` before the real CLI upgrade.

Compared with PR49's final JUnit,11 previously failing schema cases are resolved
and no new failing BMS test identities appear. The added cases also pass.
Corporate's full suite still has its original evidence-test collection fatal;
the runner separately attempted all37 files. The36 JUnit files contain144 tests:
MariaDB666 assertions /29 errors /3 failures; Percona638 /35 /2. The fatal file
is excluded from those test counts, not silently counted as passed.

[Static CI42559953577](https://github.com/rimanfatykhov-arch/bms24/actions/runs/42559953577)
passed, including the Linux symlink checks blocked by Windows privileges locally.
Local checks passed: PHP lint529/529, language/XML preflight, source preservation,
candidate-version and exact-index gate regressions, migration CLI299 assertions,
16 CLI scenarios,15 provenance cases,12 workflow-policy tests and diff whitespace.

## Remaining blockers and decision

The requested ordered-index and XMLDB-warning P0 blockers are closed for the
tested matrix. **This is not full product acceptance.** Previous communication
name-projection errors, Corporate ERP missing-mapping handling, Percona SQL around
`system`, Corporate test discovery/fixtures, privacy and other test-contract
failures remain. See PR49's report for evidence and cautious classification;
failed or interrupted security/privacy scenarios are not considered validated.

Next safe patch: correct communication user-name projection and Corporate ERP
compatibility, repair independently identified test-discovery/fixture problems,
then rerun both full matrices without waiving remaining failures.

PR48/49/50 remain unmerged. No deployment is approved. Real stage0802/Corporate.13
source reconciliation, browser/UI, optional sibling plugins, backup/restore and
live deployment remain separate work; no live rollback/downgrade is authorized.

## Evidence

MariaDB artifact13649690932, ZIP SHA256
`5a6ca89e54cb38d19e8a18f17a7d7b2e43c04541dcdd187289ae0c34b2f50035`.
Percona artifact13648894338, ZIP SHA256
`3098e632ebb425d3cdd86f600cd1aa7fb299f1bfd8784491456bb84d2165029b`.
GitHub retention14 days; local raw copies: `outputs/pr50-acceptance/` in the task
workspace. Compact results are preserved next to this file as
`pr50-schema-p0-results.json`. The final report-only commit intentionally skips
CI; the tested application and test code remain the source head identified above.
