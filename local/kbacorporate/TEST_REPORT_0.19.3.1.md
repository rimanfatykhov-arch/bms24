# KBA Corporate Dashboard 0.19.3.1 — Test Report

Build: `2026090229`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys remain in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## Regression scope

- The effectiveness card is shown only to users allowed to manage development plans.
- Company, selected department and visible employee scope are passed to the report.
- The displayed improvement count uses the same scoped effectiveness calculation as the report.
- Colour accents do not change card semantics, links or responsive grid behaviour.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify the dashboard as administrator, corporate manager and department manager.
- Check the three-column desktop layout and single-column mobile layout in the active Moodle theme.
