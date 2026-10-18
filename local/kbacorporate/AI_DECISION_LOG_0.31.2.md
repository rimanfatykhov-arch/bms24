# AI DECISION LOG — v0.31.2

## Decision
Use Moodle core Learning Plan as the authoritative competency-membership layer, while KBA remains the corporate metadata extension for role target level, GAP baseline, priority and recommended course.

## Safety / lifecycle rule
Automatic GAP rebuild is deliberately non-destructive. Existing KBA plan items are never removed merely because a GAP disappears. Pending and active plans cannot be automatically re-shaped; a manager must explicitly return the plan to draft.

## Rationale
This preserves auditability and historical intent, supports idempotent reruns, and avoids silently rewriting an agreed employee development plan.
