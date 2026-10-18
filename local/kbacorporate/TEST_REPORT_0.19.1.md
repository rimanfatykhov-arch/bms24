# KBA Corporate Dashboard 0.19.1 — Test Report

Build: `2026090226`

## Local gate

- XMLDB and incremental upgrade build are consistent.
- Russian and English language keys are unique and in parity.
- PHP/CSS structural checks and release ZIP checks pass before delivery.

## Regression scope

- Employee visibility is restricted before status/search filtering.
- Status and search filters affect both rows and all calculated metrics.
- Department scope is resolved through the existing access service.
- CSV export requires both development-plan management and report-export capabilities.
- CSV values reuse the formula-injection protection from the competency-map export.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify combined department/status/search filters under corporate and department managers.
- Open the CSV in Excel and LibreOffice and verify Cyrillic content and separators.
