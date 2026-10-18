# Test Report — KBA Corporate Dashboard 0.17.1.2

Date: 2026-09-01

Build: `2026090102`

## Local checks

- PHP structural delimiter scan across all 95 PHP files: PASS.
- `db/install.xml` parse, duplicate-name, key/index-collision and composite-index-length checks: PASS; maximum character index is 315 of 333 characters.
- English/Russian parity: PASS, 858 unique keys in each language with no duplicates or missing literal local `get_string()` keys.
- Company-scope guards on role catalog, profile, edit and assignment pages: PASS by static contract check.
- CSRF session-key guards on explicit remove/unassign operations: PASS by static contract check.
- Additive assignment service tests and explicit assignment-history retention tests added.
- Version, release, install schema and upgrade savepoint consistency: PASS for build `2026090102`.
- Full archive integrity and `kbacorporate/` ZIP root checks after packaging.

## Required on the Moodle server

PHP lint, Moodle PHPUnit and the role-management acceptance scenarios in `TESTING.md` remain required because this workspace does not include a PHP/Moodle runtime.
