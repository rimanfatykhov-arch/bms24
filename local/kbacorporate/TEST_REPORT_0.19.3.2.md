# KBA Corporate Dashboard 0.19.3.2 — Test Report

Build: `2026090230`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## UI regression scope

- Company selection is shown once and retains company context.
- Department, status, period and search filters retain their original request parameters.
- All eight KPI values use the existing report calculation without semantic changes.
- Role, department and timeline tables retain their original data and links.
- Wide tables use local overflow containers; the page itself should not gain horizontal scrolling.
- KPI grid adapts from four columns to two and then one.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Clear Moodle caches after installation.
- Verify the page at desktop, tablet and phone widths in the active KBA theme.
- Confirm page and CSV values remain identical under company and department-manager scope.
