# KBA Corporate Dashboard 0.19.3.6 — Test Report

Build: `2026090234`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys remain unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## Navigation regression scope

- Dashboard navigation retains the selected company ID.
- Competency-map navigation is shown only with competency-view capability.
- Navigation appears both before and after the role catalog.
- Empty and unavailable states retain a safe return path.
- Existing role creation, template and assignment actions remain unchanged.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify navigation as site administrator and corporate manager.
- Check the role table on desktop and mobile widths.
