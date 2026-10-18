# KBA Corporate Dashboard 0.26.4

This release adds operational checklists to BMS implementation tasks.

- Add checklist items directly inside an active task.
- Mark individual items complete or reopen them.
- Display completed/total progress in the task workspace, curator Kanban, and employee task list.
- Preserve the checklist as read-only after the task is completed or cancelled.
- Limit item deletion to the item author, curator, or authorised implementation manager.
- Include checklist actor data in Moodle privacy export and anonymisation workflows.
- Correct remaining task-filter link attributes for Moodle 4.0.

Build: `2026090276`.

After installation, complete the Moodle database upgrade and purge all caches.

Recommended acceptance path: open an active task, add three checklist items as curator, mark items complete as the assigned employee, verify progress on the task and Kanban, then complete the task and confirm that its checklist is locked.
