# Static test report — 0.26.4

- Version and XMLDB versions match build `2026090276`.
- Upgrade savepoint creates the additive checklist table only when absent.
- Checklist writes require an authorised task participant and a valid session key.
- Checklist rows are scoped to the task ID for toggle and delete operations.
- Completed and cancelled tasks reject checklist mutations.
- Kanban and employee task queries expose completed/total checklist counts.
- Moodle privacy metadata, context discovery, export, anonymisation, and user lists include checklist actors.
- Company deletion removes checklist rows before parent tasks.
- Moodle 4.0 link attributes use arrays on the touched task pages.

PHP CLI is not available in the local build environment; runtime verification is required on Moodle.
