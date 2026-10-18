# AI DECISION LOG — v0.31.5

## Decision 1 — Dedicated employee GAP projection

A separate read-only employee GAP service and page were introduced instead of embedding more logic into the role-learning matrix. This keeps role design, employee assessment and Learning Plan provisioning as distinct workflows.

## Decision 2 — Confirmed level is authoritative

Only the Moodle user competency grade is used as current level. Pending audit results and course-scoped results are displayed as provisional context but do not close GAP.

## Decision 3 — Unknown is not zero

An unassessed competency remains `null`. It is counted separately and prevents the calculation from being described as complete.

## Decision 4 — Multi-role aggregation is reused

The existing `target_profile` service remains the single aggregation contract: highest target level, strongest priority, and mandatory when any assigned role marks the competency mandatory.

## Decision 5 — No automatic writes

The release does not create audits, role assignments, course mappings or Learning Plans. It only links authorised users to those existing workflows.

## Decision 6 — Course coverage is employee-actionable

Course coverage uses the existing company-scoped recommendation service, so hidden or unrelated courses are not disclosed and only enrolled or assignable courses are presented.
