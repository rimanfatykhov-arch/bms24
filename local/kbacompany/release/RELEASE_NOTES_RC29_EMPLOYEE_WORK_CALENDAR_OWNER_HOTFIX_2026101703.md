# BMS24 1.1.0-rc29 / build2026101703

## Employee work-calendar owner resolution hotfix

Live defect: opening `employee_work_calendar.php?uuid=<company>&userid=2` from My Management Cycle could throw `bms_employee_not_found` when the Moodle account had company access / management-cycle ownership but no canonical employee membership in that company.

### Fix

- Added `employee_work_calendar_service::owner_available()` to resolve a work-calendar owner through the canonical employee directory without leaking missing-employee details.
- `employee_work_calendar.php` now intercepts an invalid own-calendar target before calling permission methods and redirects back to My Management Cycle with a clear warning; invalid foreign targets remain opaque permission failures.
- My Management Cycle no longer renders an actionable work-calendar link when the current account is not linked to a canonical company employee. The step remains visible with an explanatory warning, so the management-cycle model is not silently changed.
- Existing employee-profile and position-coverage calendar links remain unchanged and continue to use canonical employee IDs.

### Safety

- No DDL.
- No automatic employee creation or membership mutation.
- No weakening of calendar ACL/grants.
- No cross-company fallback.
- Existing company context and Employee directory remain authoritative.

### Live acceptance

NOT RUN. Verify: (1) current management user without employee membership no longer gets a fatal; (2) employee calendar still opens for a linked employee; (3) foreign/non-company user IDs remain inaccessible.
