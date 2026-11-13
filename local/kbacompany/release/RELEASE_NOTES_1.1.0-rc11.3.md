# local_kbacompany 1.1.0-rc11.3 — Staffing assignment carry-forward hotfix

Build: 2026101230

## Fixed
- Approving a new staffing-plan version no longer makes current employee assignments disappear when the same canonical position and compatible FTE slot remain in the new version.
- Compatible factual assignments are carried forward to newly generated staffing slots without ending/recreating the position assignment.
- A change in staffing capacity/FTE never silently changes or ends an employee assignment. Assignments that cannot be safely mapped remain active and are shown in Staffing placement as «Placement required».
- Staffing occupancy calculations use canonical position assignments, so the draft/new version keeps showing factual occupancy across plan versions.
- Salary/FTE validation errors now return the user to the BMS24 staffing editor with an error notification instead of the Moodle system error page.

## Safety
- No database schema change.
- Company scope remains applied in staffing reads and writes.
- Approved staffing history is retained; no employee assignment is silently deleted.
