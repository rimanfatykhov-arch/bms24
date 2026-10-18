# KBA Corporate Dashboard 0.19.3.8 — Test Report

Build: `2026090236`

## Local gate

- XMLDB, version and upgrade builds are consistent.
- Russian and English language keys remain unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## Navigation regression scope

- A manager viewing `Мои компетенции` returns to the selected company dashboard.
- An ordinary employee returns to the personal Moodle dashboard.
- A profile without a resolved company still has a safe return route.
- Company context is preserved whenever corporate-dashboard navigation is available.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify the page as administrator, corporate manager and ordinary employee.
