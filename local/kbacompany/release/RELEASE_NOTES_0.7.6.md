# local_kbacompany 0.7.6 — Learning Linkage

Build: 2026092314

## Added

- Canonical `courseid` linkage on Development Plan actions.
- Company-catalogue course selector sourced from KBA Corporate bridge data.
- Live Moodle enrolment/completion status for the employee on each linked action.
- Direct course link from the development action.
- Learning ownership contract updated to 0.7.6.

## Boundaries preserved

- BMS does not enrol users into courses.
- BMS does not write completion, grade or certificate state.
- Course completion does not update competency attainment automatically.
- Only approved Assessment Engine decisions can update employee competency facts.
- Company UUID remains the security boundary before data fetch.

## Schema

Additive field on `local_kbacompany_devitem`:

- `courseid` INT(10) NOT NULL DEFAULT 0

Upgrade savepoint: `2026092314`.
