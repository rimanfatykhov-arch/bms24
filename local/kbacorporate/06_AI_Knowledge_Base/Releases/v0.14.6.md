# KBA Corporate Dashboard 0.14.6 — Release Notes

## Purpose
Expose additive corporate role synchronisation for KBA Connect.

## Public API
New method:

`merge_employee_roles(int $userid, int $companyid, array $roles): array`

Allowed rolecodes:
- `employee`
- `corporate_manager`
- `department_manager`
- `hr`

Strategy: **MERGE**. Incoming roles are unioned with existing roles. Existing roles are never removed by this method.

## Storage
The existing unique membership `(companyid, userid)` remains the identity. Two additive flags were added:
- `isdepartmentmanager`
- `ishr`

No role-per-row membership model is introduced.

`department_manager` on the company membership is a business classification only. Access to a specific department still requires the existing department assignment model.

## Upgrade
Upgrade build: `2026081804`.

The migration adds only two fields and indexes. No destructive data migration is performed.
