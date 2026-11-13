# local_kbacompany 1.1.0-rc5 — Staffing Plan Version Editor

Build: 2026101221

## What changed
- New staffing-plan drafts copy the current approved plan by default.
- Explicit empty-draft mode remains available by clearing the copy checkbox.
- Draft version metadata is editable: name, effective date, currency.
- Staffing lines are editable inline: FTE, salary range, monthly budget.
- Draft lines can be removed without altering historical approved versions.
- New positions can be added to the draft.
- Draft shows a comparison against the previous approved version: added / changed / removed.
- Approval archives the prior version and closes its effective interval when the new version has an effective date.
- Approved versions remain immutable and staffing placement remains a separate factual layer.

## Data model
No schema changes. Existing StaffingPlan / StaffingPlanLine / StaffingSlot objects are reused.

## Acceptance note
Live Moodle visual/runtime acceptance has not been run in this environment.
