# RC29 build2026101479 — narrowly-scoped create grants

- `task:create` (company): actor can create a canonical task for a verified company employee; **does not** confer edit/manage/view-all rights for preexisting tasks. Existing self-create unchanged.
- `battle_plan:create` (company): actor can use the normal creation endpoint to create an employee plan only for a verified employee and position. Existing plans remain unreadable/uneditable without row-based permissions; cannot hijack existing plan.
- Role UI: create grant toggles and effective create status, action-scope `company`; no automatic role assignment.
- `task:edit` / `battle_plan:edit` legacy broad permissions unchanged. `approve` is **not wired**, and `create` is **not implemented** across every generated-task/coverage workflow.
- No new tables, no data migrations. Static checks only: Moodle live acceptance and integration tests not run. **FINAL NOT READY**.
