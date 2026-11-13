# RC29 build 2026101685 — Team Attention → Task Center handoff

## Added

- Team attention rows can open the canonical Task Center create form with employee, reviewer, week and Battle Plan prefilled.
- The action is shown only for editable `draft` / `active` Battle Plans.
- Task Center can now create a manual canonical task directly into an editable draft/active Battle Plan by reusing `battle_plan_service::create_manual_task()`.
- No task is created until the user completes the normal Task Center form and saves it.

## Architecture boundary

- No new task entity or management-action table.
- Existing Battle Plan edit permissions remain authoritative.
- Existing Task Center validation remains authoritative for task fields.
- No schema, privacy or company-isolation changes.
