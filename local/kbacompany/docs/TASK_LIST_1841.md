# BMS24 Task List 4.0 — build2026101841

## Purpose

Task List 4.0 improves the existing canonical `local_kbacompany` task list.
It does **not** create a second task store, duplicate tasks, modify schemas or
change task/employee/Battle Plan ACLs.

## Public routes

- Existing `/local/kbacompany/tasks.php?uuid=...` remains the task list.
- Optional bookmarkable GET parameters `sort` (allowlisted) and `dir` (`asc`/`desc`)
  affect only the presentation of the filtered list.
- POST `/local/kbacompany/task_list_preferences.php` changes the logged-in
  employee's **per-company** view preferences. Requires a valid Moodle session,
  `sesskey`, company membership and the existing capability.

## Sorting

The count and list SQL use the same `list_conditions()` permission predicate.
Sorting uses `task_list_preferences_service::sql_order()` to select only fixed
SQL expressions. It is applied to all matched rows **before** paging, with
`t.id ASC` as the stable tie-breaker. Blank deadlines always sort last.
Sorts: title, assignee, creator, status, deadline, priority, source, updated,
created. Employee name sorts use joined Moodle user surname and firstname,
without changing the visibility predicate.

## User preferences

Stored as Moodle user preferences (no table or migration), keyed by user and
company UUID. Optional visible columns are selected from an allowlist;
`title` is mandatory. Each user can also select their default sorting field and
direction. Explicit bookmark sort GET parameters take precedence for that
request. Saving settings returns to the same company and role/status filters;
untrusted redirect URLs are not accepted.

## UI compatibility

- BMS24 owned Task List: configurable native `<table>` with sorted headings
  (`aria-sort`) and responsive horizontal scrolling.
- BMS24 Task Kanban and Analytics: unchanged views and task predicates.
- Legacy Task List: original static table and controls retained.
- No modifications to Task CRUD, subtask lifecycle, Battle Plan links,
  checklist controls, or shared KBA Theme logic.

## Manual acceptance

1. Check all roles vs company-wide role scope with employee and manager.
2. Open Task List with >25 tasks; compare page 1 and 2 for title ASC/DESC.
3. Test empty deadlines with both sort directions and stable ties.
4. Select columns, save; refresh, log out/in; switch to another company.
5. Verify column preferences do not leak to another employee or company.
6. Verify Task Kanban, Analytics and Task Detail unchanged.
7. Test POST without `sesskey` and `GET` on preferences endpoint: both rejected.
8. Verify Unicode and HTML-containing task titles are escaped.
