# RC29 / 2026101481 — Department manager creates an employee Battle Plan

- `battle_plan:create` with `scope=department` is granted to a custom access role, then effective ONLY where the assignee is a factual organizational-unit manager and target employee shares that unit.
- Creation form presents own employee(s) plus employees in managed departments. Server independently rechecks the target company, current position, reviewer, and entitlement.
- Creates **draft** for selected employee with selected reviewer; ownership belongs to the employee, not creator. A scoped creator can review their own newly-created **draft** while they still manage its owner; no automatic edit, submit, approve, or close permission.
- A current existing plan for another person is **not** returned under scoped-only create; avoids exposing an existing plan. One plan per employee/position/week remains canonical.
- A role grant may be `create:company` OR `create:department`, not both. All earlier grants remain untouched. No schema change.
- This stage does not implement editing other people's plans, task completion, or task approval. Test with real Moodle: ordinary manager, delegated RBAC manager, employee same vs other department, direct POST, existing plan, multiple managers, revoked grant, cross-company.
- Static verification is not live Moodle acceptance. FINAL NOT READY.
