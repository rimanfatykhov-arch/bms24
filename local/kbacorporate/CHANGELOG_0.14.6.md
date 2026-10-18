# KBA Corporate Dashboard 0.14.6 — Changelog

- Public API upgraded to 1.13.
- Added `bridge::merge_employee_roles(int $userid, int $companyid, array $roles)`.
- Role synchronisation is additive MERGE only; no existing/manual role is removed.
- Unified company membership now stores `department_manager` and `hr` business roles alongside employee and corporate manager.
- Added `isdepartmentmanager` and `ishr` fields with non-unique indexes.
- Members UI displays Employee, Corporate manager, Department manager and HR on one membership row.
- Unknown rolecodes are ignored safely and returned in `ignored_roles`.
- Existing Health Center, pairing, schema provisioning, licensing, BBB and certificate functionality retained.
