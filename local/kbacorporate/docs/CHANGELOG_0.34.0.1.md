# Changelog v0.34.0.1

## Added

- Scoped capability `local/kbacorporate:managecompanyimplementation`.
- Company-scoped BMS implementation access helpers.
- Automatic capability grant to the generated `kbacorporatemanager` role.
- Regression tests for own-company access and cross-company denial.

## Changed

- Corporate dashboard BMS card now uses customer-scoped implementation access.
- Customer-facing audit, plan, task, evidence, comparison and section-progress pages use the scoped access boundary.
- BMS navigation separates customer operations from global methodology administration.
- Task/evidence notifications include authorised corporate managers of the same company.
- Global Kanban and methodology links are hidden from scoped customer managers.

## Security

- Full company membership remains mandatory even when the new capability is assigned.
- Framework Manager, global BMS tools and JSON administration remain protected by `manageimplementationaudits`.

## Database

- No schema changes.
- Upgrade savepoint: `2026090902`.
