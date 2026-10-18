# Test Report v0.33.3

## Scope

Статическая, контрактная и упаковочная проверка экрана прогресса внедрения по разделам BMS.

## Environment

- Source baseline: `v0.33.2 / 2026090812`
- Target build: `2026090813`
- Minimum supported Moodle: 4.0
- Runtime Moodle instance: not available in build container

## Static release gate

| Test | Expected | Result |
|---|---|---|
| PHP syntax for all plugin PHP files | no syntax errors | PASS |
| `db/install.xml` XML parse | valid | PASS |
| install.xml unchanged from v0.33.2 | identical SHA-256 | PASS |
| version metadata | build `2026090813`, release `0.33.3` | PASS |
| upgrade path | savepoint `2026090813` | PASS |
| RU/EN language key parity | identical key sets | PASS |
| duplicate language keys | none | PASS |
| literal plugin string references | all defined | PASS |
| ZIP root | only `kbacorporate/` | PASS |
| ZIP integrity | no corrupt entry | PASS |
| destructive migration | absent | PASS |

## Code-level scenarios defined

`tests/implementation_section_progress_test.php` covers:

1. separation of audit maturity, action coverage, evidence and operational risk;
2. unique criterion coverage when actions exist;
3. execution progress by controlled task status;
4. accepted evidence counting;
5. overdue and unassigned actions;
6. cancelled action does not cover a criterion gap;
7. an unassessed section is not reported as achieved.

The PHPUnit class is included but was not executed because a configured Moodle PHPUnit environment is unavailable in the build container.

## Required pilot runtime tests

1. Upgrade a database copy from `2026090812` to `2026090813`.
2. Open the page as site manager, company manager and assigned curator; verify company scope.
3. Select a confirmed audit with the 15-section BMS tool; verify section count and averages against the audit.
4. Verify unassessed and not-applicable criteria are classified correctly.
5. Create an action for a gap criterion and confirm action coverage increases without changing the audit score.
6. Move the action through planned, in-progress, review and completed; verify execution progress.
7. Submit, return and accept evidence; verify evidence counts and status.
8. Create an overdue and an unassigned action; verify risk counters and section status.
9. Compare with a previous confirmed audit; verify section and BMS Index deltas.
10. Export CSV and verify selected company/audit, encoding, columns and formula protection.
11. Confirm users outside the company/curator scope cannot open the report or export.

## Verdict

Prepared for pilot runtime validation. The release must not be declared Stable until the runtime scenarios above are recorded.
