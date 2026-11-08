# RC29 build 2026101669 — Next-week continuity context

## What changed

- The canonical next-week create form now explicitly shows that it continues from the previous weekly Battle Plan.
- It shows the source week and the reusable settings that were successfully inherited: reviewer, observer, plan template and norm set when still valid.
- It explicitly states that canonical tasks, metric facts, weekly goal/outcome and review history are not copied into the new week.
- The continuity notice survives a validation error on the create form, while changing employee/position context still drops the source and returns to the normal create flow.

## Safety

Presentation/read-model only. No DB schema, ACL/RBAC, lifecycle, privacy or company-isolation changes. Existing `create_plan()` remains authoritative.
