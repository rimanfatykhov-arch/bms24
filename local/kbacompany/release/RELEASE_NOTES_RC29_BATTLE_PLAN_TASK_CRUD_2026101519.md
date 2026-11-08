BMS24 1.1.0-rc29 / build2026101519 — Battle Plan Task Create BMS CRUD
- Two exclusive workflows: create a new task (default), or add existing one (search/list, admin scale, position work, task library).
- Standard stacked label/control, expected result prominent, description second.
- Optional settings within collapsible details: priority, stream, reviewer, observer, dates/times, minutes, file.
- Keep canonical service handlers, active company context, POST/sesskey, role checks, original task creation + plan linking semantics.
- Preserve submitted values and surface errors on validation failure.
- No schema changes.
- Static PHP lint pass; Moodle browser test not performed.
