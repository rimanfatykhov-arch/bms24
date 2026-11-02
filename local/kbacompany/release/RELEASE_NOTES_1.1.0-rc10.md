# local_kbacompany 1.1.0-rc10 — Staffing Coverage

## Scope

This release connects the versioned normative staffing plan with factual staffing placement in one user-facing view.

- Each staffing line shows planned FTE, occupied FTE and vacant FTE.
- Draft versions use factual occupancy from the current approved version so proposed changes can be assessed before approval.
- Approved/archived versions read occupancy from their own staffing slots and current assignments.
- Coverage states are explicit: fully staffed, vacancies, over plan.
- Company and department summaries include occupancy/vacancy totals.
- No second staffing store and no database schema change.
- Approval still does not automatically reassign employees; staffing placement remains the canonical factual layer.
