# RC29 build 2026101668 — Next-week continuity

## What changed

- “Create plan for next week” now opens the canonical create flow with reusable settings from the current active/closed plan prefilled.
- Reviewer and observer are inherited only while they are still valid company employees.
- Plan template and norm set are inherited only while the same published revisions remain available for the position.
- The new week still gets its own canonical Battle Plan record. Tasks, metric facts, weekly goal/outcome and review history are not copied.
- Changing employee/position context intentionally drops the continuity source and returns to the normal create flow.

## Safety

No DB schema, ACL/RBAC, lifecycle, privacy or company-isolation changes. Existing `create_plan()` remains authoritative and validates all submitted settings.
