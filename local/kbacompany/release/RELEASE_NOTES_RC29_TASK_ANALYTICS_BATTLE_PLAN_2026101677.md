# BMS24 1.1.0-rc29 — build 2026101677

## Task Center analytics in Battle Plan context

- Analytics respects the existing Battle Plan filter.
- The selected Battle Plan is shown explicitly with a direct return link.
- Added read-only completion share, overdue share among open tasks, and review queue count.
- All metrics are derived from the same filtered canonical task query used by list/kanban.
- No DB schema, ACL/RBAC, lifecycle, privacy, company-isolation, or task-plan relation changes.
