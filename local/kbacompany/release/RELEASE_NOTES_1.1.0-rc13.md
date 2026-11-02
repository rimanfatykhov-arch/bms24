# local_kbacompany 1.1.0-rc13 — Staffing Placement 2.0

Build: `2026101233`

## Scope

This RC turns **Штатная расстановка** into a factual assignment workspace while keeping the approved staffing plan normative and immutable.

### Assignment CRUD
- create an employee assignment into a staffing slot;
- edit assignment type, FTE share and effective dates;
- transfer an employee to another staffing slot/position without rewriting history;
- end an assignment without deleting its history;
- view current/scheduled assignments, vacancies and assignment history on one screen.

### FTE and capacity
- `PositionAssignment.assignmentfte` stores factual occupied FTE separately from normative `StaffingSlot.fte`;
- one staffing slot may contain several compatible partial assignments while total overlapping FTE never exceeds slot capacity;
- primary assignment overlap for the same employee is rejected; use additional assignment for secondary employment;
- acting/temporary assignments support an explicit end date.

### Version safety
- edits are allowed only against the current approved staffing-plan version;
- historical staffing-plan versions remain read-only;
- transfer creates/retains dated history rather than mutating a historical position;
- assignment carry-forward to a new staffing-plan version packs factual FTE into compatible capacity and never silently reduces employee FTE.

## Schema

Adds `local_kbacompany_posassign.assignmentfte NUMBER(5,2) NOT NULL DEFAULT 1`.
The upgrade backfills existing slot-backed assignments from the FTE of their historical staffing slot.

## Acceptance note

Static/package QA was executed. Live Moodle runtime and real-company acceptance are still required before final release.
