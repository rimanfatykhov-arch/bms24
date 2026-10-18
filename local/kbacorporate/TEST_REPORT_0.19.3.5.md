# KBA Corporate Dashboard 0.19.3.5 — Test Report

Build: `2026090233`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## Filter regression scope

- Exact company selection uses an integer company ID.
- Status accepts only all, active and inactive values.
- Search covers company name, company code and Bitrix24 ID case-insensitively.
- Filters combine by AND and the result counter reflects the final set.
- Existing company actions remain unchanged.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Test each filter independently and in combination.
- Verify Cyrillic name search, reset and the no-results state.
- Clear Moodle caches and check the table at mobile width.
