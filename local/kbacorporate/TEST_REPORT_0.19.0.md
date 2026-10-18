# KBA Corporate Dashboard 0.19.0 — Test Report

Build: `2026090225`

## Local gate

- XMLDB and incremental upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks and release ZIP checks pass before delivery.

## Regression scope

- The company report can be restricted to an explicit set of visible employee IDs.
- Plan/action counts, completion percentage, overdue totals and follow-up audit coverage are aggregated.
- Confirmed outcomes reuse the same authoritative before/after calculation as the plan page.
- The end-to-end development plan test covers company effectiveness after audit creation.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify corporate-manager and department-manager scopes against two departments.
- Verify the analytics page on the production theme with multiple plans and employees.
