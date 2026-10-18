# Static test report — 0.27.0

- Version and XMLDB versions match build `2026090277`.
- Upgrade chain contains a no-schema savepoint for `2026090277`.
- Analytics query enforces curator scope unless the user has implementation-management capability.
- Company, curator and period filters are applied server-side.
- Completion, overdue, average-cycle and checklist formulas are covered by a PHPUnit scenario.
- Latest BMS Index is read only from confirmed company audits.
- CSV uses the same filtered rows and neutralises formula-leading values.
- All navigation and Moodle 4.0 link attributes pass static validation.

PHP CLI and a Moodle test database are not available in the local build environment; runtime verification is required on Moodle.
