# RC29 build2026101742 — Verified repeated task carryovers

Read-only manager history for the last 8 weeks. Counts a carryover only when the source plan records its item UUID, the authorized target plan is the next week for the same employee, and the target contains the same canonical BMS task. Shows tasks with 2+ proven carryovers. Filters company, authorized plans and `task_service::can_view_task`. No new DDL or write actions. Past missing events are not inferred.
