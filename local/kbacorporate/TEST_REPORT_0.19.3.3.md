# KBA Corporate Dashboard 0.19.3.3 — Test Report

Build: `2026090231`

## Local gate

- XMLDB, version and upgrade build are consistent.
- Russian and English language keys remain in parity.
- PHP/CSS structural and ZIP integrity checks pass before delivery.

## Navigation regression scope

- Managers receive a return link to the company IPR list.
- Employees receive a return link to their own competency profile.
- The dashboard link is emitted only when the viewer has dashboard capability.
- Every link retains the development plan's company ID.
- Navigation is repeated after the plan workflow so long plans do not require scrolling back to the top.

## Server gate still required

- Run `php -l` and Moodle PHPUnit.
- Verify links as administrator, corporate manager and employee.
- Confirm a department manager cannot navigate outside the permitted employee/company scope.
