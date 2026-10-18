# Static test report — 0.27.1

- Version, XMLDB schema marker and upgrade savepoint match build `2026090278`.
- KPI and comparison links pass company, curator, status, overdue and period filters to the Kanban.
- Kanban filter values are allow-listed before use and access remains scoped by curator assignment or implementation-management capability.
- Monthly dynamics count created and completed tasks and calculate average completion cycle.
- BMS Index change uses only the two latest confirmed company audits.
- CSV includes the same filtered index delta and monthly dynamics and retains formula-injection protection.
- Russian and English language keys and Moodle 4.0 link attributes pass static validation.

PHP CLI and a Moodle test database are not available in the local build environment; runtime verification is required on Moodle.
