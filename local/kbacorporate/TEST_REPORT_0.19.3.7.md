# KBA Corporate Dashboard 0.19.3.7 — Test Report

Build: `2026090235`

## Local gate

- XMLDB, version and upgrade builds are consistent.
- Russian and English language keys remain unique and in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.
- All audited company-scoped screens use the shared dashboard-navigation renderer.

## Navigation regression scope

- Return URLs retain the selected company ID.
- The link is hidden without `local/kbacorporate:viewdashboard`.
- Existing parent links on edit and detail pages remain available.
- Members, courses, departments, competency map, competency audits, business roles, templates, licenses and development effectiveness are covered.
- Global company editing, integrations, Health Center and version information remain unchanged.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify the link as site administrator, corporate manager and ordinary employee.
- Verify that each link returns to the same selected company.
