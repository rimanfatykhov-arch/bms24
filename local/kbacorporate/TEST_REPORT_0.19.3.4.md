# KBA Corporate Dashboard 0.19.3.4 — Test Report

Build: `2026090232`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys remain unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## UI and security regression scope

- Plan counts and rows are restricted to the existing visible employee ID set.
- Creation remains restricted to the same visible employee ID set.
- Status counters are calculated from the scoped rows.
- The progress heading uses the plugin's existing development-progress string.
- Status badges preserve the canonical plan states.
- The report table scrolls inside its container on narrow screens.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify the page as corporate and department managers.
- Confirm a department manager cannot see or count plans outside the permitted department.
- Clear Moodle caches and check desktop and mobile layouts.
