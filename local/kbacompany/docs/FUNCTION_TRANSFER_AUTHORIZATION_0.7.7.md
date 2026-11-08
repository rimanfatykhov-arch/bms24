# Function Transfer & Authorization — 0.7.7

## Purpose
Records a controlled handover of a canonical BMS Function from the current responsibility context to a receiving employee/position.

## Lifecycle
Draft → Pending authorization → Authorized → Accepted. A transfer may be revoked; a dated active/accepted transfer is shown as expired after its end date.

## Boundaries
- Company scoped.
- References the canonical published Function; it does not clone the Function.
- Does not rewrite Position, staffing slot or organizing board assignments.
- Does not grant Moodle capabilities. Platform access remains governed by existing permissions.
- Acceptance is explicit by the designated receiving employee.
- `supervised` supports practice under control before later reassessment/authorization decisions.
