# KBA Corporate Dashboard 0.19.3 — Test Report

Build: `2026090228`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks and ZIP integrity checks pass before delivery.

## Regression scope

- Timeline accepts only completed plans with their explicitly linked follow-up audit.
- Only confirmed audit items with a confirmation timestamp contribute.
- Historical baseline is read from the IPR item and is not recalculated from a live Moodle grade.
- An item without a baseline is assessed but not included in before/after averages or improvement.
- Company, employee visibility, department, status and search restrictions are inherited from the filtered plan rows.
- The selected period is preserved in the CSV export.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Complete a follow-up audit, then compare page and CSV monthly totals.
- Change the employee's Moodle level again and confirm that the historical before/after pair does not change.
