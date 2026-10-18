# KBA Corporate Dashboard 0.19.2 — Test Report

Build: `2026090227`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks and ZIP integrity checks pass before delivery.

## Regression scope

- Breakdowns use only rows already restricted by company, department, status and search scope.
- Multi-role employees contribute to each current assigned role.
- Unassigned role/department fallback groups retain otherwise valid plans.
- Group progress and outcome metrics are derived from the canonical plan rows.
- Role and department sections are present in the CSV export.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify a multi-role employee and a multi-department employee in production.
- Compare on-screen and CSV group totals under department-manager scope.
