# BMS24 1.1.0-rc29 / build2026101707

## Pattern / Task → factual result feedback

Weekly Management Coordination now closes the factual management loop for a recurring pattern after a linked canonical management task is completed.

### Behavior

- A completed linked Task Center task is identified by the existing BMS24 pattern trace marker and canonical task visibility.
- Task completion uses the canonical task `completed_at` timestamp.
- The comparison reuses the already selected bounded 4/8-week management history for the same accessible employee scope.
- **Before** = latest available week at or before the task completion week.
- **After** = latest available subsequent week.
- The pattern card shows only factual Productivity / Effectiveness / Data Coverage values as before, after and percentage-point delta, with the corresponding weeks and task completion date.
- If no subsequent comparison data exists yet, the card says: `Недостаточно последующих данных для сравнения.`
- If the bounded history does not contain a usable before point, the UI reports insufficient before data instead of inferring a baseline.
- The required causality disclaimer is always displayed with factual result feedback.

### Canonical Task feedback regression fix

The previous Pattern → Task feedback code called `task_service::manual_tasks_by_trace_marker()` but that method was absent from the packaged build1706 class. Build1707 adds the missing bounded read method using the existing canonical Task Center projection visibility predicate. Marker lookup is authoritative for traceability; an exact title + assignee compatibility fallback is retained only for build1704 tasks created before trace markers.

### Architecture / safety

- No DDL.
- No snapshot/result table.
- No causal model or success score.
- No employee ranking.
- No new task or Battle Plan entity.
- GET remains read-only and never creates a task.
- Task Center ACL/RBAC, company isolation and canonical task lifecycle remain authoritative.
- Trace marker is metadata, never an authorization boundary.

### Live acceptance

NOT RUN. Verify on live Moodle with one employee scope: recurring pattern → linked task → complete task → return to Weekly Coordination → factual before/after/delta. Also verify a task completed in the current/latest week shows `Недостаточно последующих данных для сравнения.` until a later week with data exists.
