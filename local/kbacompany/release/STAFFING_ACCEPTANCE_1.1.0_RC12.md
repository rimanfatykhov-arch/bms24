# Staffing Plan RC12 — live acceptance checklist

Target: local_kbacompany 1.1.0-rc12 build2026101232

1. Open the current approved Staffing Plan and click «Изменить штатное расписание».
2. Confirm that the draft is copied from the current approved version and all existing staffing lines are present.
3. Change salary/FTE for an existing line and save the draft; current employee placement must remain visible.
4. Add a new staffing unit and confirm Plan / Occupied / Vacant / Overstaffed values.
5. Remove a position that currently has an employee assignment. The Approval Impact block must list that employee as requiring placement.
6. Reduce capacity so an existing assignment cannot map to an equal-FTE slot. The impact block must show the conflict.
7. Approval with conflicts must require the explicit acknowledgement checkbox.
8. Approve the version. Compatible assignments must remain assigned; incompatible assignments must remain active and appear as «Требует размещения» in Staffing Placement.
9. Open Staffing Placement and verify no current assignment was silently deleted or ended.
10. Enter an invalid salary range. The error must return inside the BMS24 staffing editor, not to the Moodle system error page.

Live production/runtime acceptance was not executed during packaging.
