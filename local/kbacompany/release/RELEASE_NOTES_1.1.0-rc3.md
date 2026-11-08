# local_kbacompany 1.1.0-rc3 — Task → employee Battle Plan

Build: `2026101217`

## Scope

- Makes the Task Center's Battle Plan selection explicit for another employee.
- Accessible draft plans are grouped by employee and show the employee's position and plan period.
- The selected plan owner must equal the task assignee.
- Managers retain the existing ability to attach the canonical task to another employee's accessible draft plan.
- No task copy is created.

## Safety

- Existing Company scope and Battle Plan draft-access checks remain authoritative.
- No database schema, capability or privacy-storage changes.
