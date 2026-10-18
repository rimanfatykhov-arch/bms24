# KBA Corporate Dashboard v0.31.5
## Employee GAP Calculation

**Build:** `2026090615`  
**Component:** `local_kbacorporate`  
**Classification:** MINOR, backward-compatible  
**Database changes:** none

## Purpose

Introduce a dedicated company-scoped employee GAP calculation before Learning Plan preview and provisioning.

The release closes the verified chain:

`Employee → assigned business roles → merged target competency profile → confirmed current levels → GAP → course coverage → next action`.

## Added

- New page `/local/kbacorporate/employee_gap.php`.
- Employee selector restricted to employees visible to the current user.
- Assigned business roles and merged role-profile requirements.
- Confirmed current level versus target level calculation.
- Separate counters for mandatory and supporting GAPs.
- Critical-priority GAP counter and total GAP points.
- Assessment coverage and target-readiness percentages.
- Course availability for every competency GAP.
- Explicit statuses: GAP, not assessed, awaiting confirmation, target achieved.
- Filters by status, priority, mandatory/supporting type and text search.
- Links to competency audit, course coverage, employee competency profile and GAP Learning Plan preview.
- Entry links from the employee card, role-learning matrix and GAP Learning Plan page.

## Data policy

- Moodle core `competency_usercomp` remains the authoritative current-level source.
- Pending audit and course results remain provisional and are never promoted to confirmed current level by this report.
- Role targets are aggregated by the existing target-profile rules: highest target level, strongest priority and mandatory if any assigned role requires it.
- Domain nodes `BMS-Dxx` remain excluded from the working target profile.

## Safety

- The page is read-only.
- No assessment, business-role assignment, course mapping or Learning Plan is created automatically.
- No destructive migration is included.
- Existing employee data and Moodle Learning Plans are unchanged.

## Upgrade

Install the full ZIP over v0.31.4. Moodle records savepoint `2026090615`; no database schema migration is executed.
