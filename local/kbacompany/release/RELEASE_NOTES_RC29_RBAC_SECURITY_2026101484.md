# RC29 build2026101484 — task RBAC bypass closure (partial security regression)

## Corrected
- A task reader via `task:view` with `department` scope can no longer use a crafted POST to add an ordinary attachment or add a checklist item. This is checked inside `task_service`, not only the UI.
- Existing task owner/creator/reviewer/observer and department-edit roles remain allowed to contribute; the permission to view another task does not grant writing rights.
- UI action eligibility now agrees with server checks for these two actions. Deleted tasks do not accept new checklist items.
- Moodle pluginfile callback already checks `task_service::can_view_task` by company+UUID before serving stored files. Retained.

## Limitations / acceptance gates
- This is a targeted hardening release, **not** a claim that every task/battle-plan/communication or bulk operation was audited. Bulk and legacy endpoints still require dedicated review.
- No database schema changes. No live Moodle, Behat or PHPUnit run. PHP CLI lint does not prove PHP 7.3 compatibility.
- Test read-only department manager against both `attachmentadd` and `checkadd` via POST, verify no data mutation, and verify add still works for real task participants.
