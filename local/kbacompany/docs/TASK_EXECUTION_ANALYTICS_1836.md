# BMS24 Task Execution Analytics — build2026101836

Entry: `tasks.php?uuid=<companyuuid>&display=analytics`.

Canonical source: `local_kbacompany_task`, accessed through `task_service::list_conditions()`; all queries use `projection_access` and scoped parameters. There is no second task store and no extra capability.

Counters: 9. Aggregations: assignees (top 15), directly linked management projects (top 10), attention queue (top 12). Only reports of visible tasks. All metrics reflect current scope, search, stream, source, Battle Plan and optional status/assignee/project filters. The list-only signal is cleared when entering analytics and restored on KPI drill-down.

A task is counted under a project only if its own `contextkind` equals `project`; a child task's parent project is not inferred, by design. Employee groups use the *original* `assigneeid`, not the effective temporary executor. Totals use all rows satisfying visibility, not the 25-row paginated list.

No schema migration; 1836 adds a standard upgrade savepoint. PHPUnit regression tests reside in `tests/task_execution_analytics_test.php` and need a configured Moodle test instance.


## build2026101837 fixes
- `open` KPI/drill-down uses the same `status NOT IN (done,cancelled)` predicate as the card.
- Employee/project table figures drill into list views with preserved company/scope and filters.
- Completion progress is computed as `done / (total - cancelled)`; the total counter still includes cancelled records.
- Empty filters show an explicit no-results note, and a missing deadline uses the shared `No due date` label.
- Search for a task to link as a subtask has live suggestions with a manual fallback; linking still uses the canonical POST endpoint with `sesskey`, revision and cycle checks.
