# BMS24 1.1.0-rc29 / build2026101704

## Pattern → Action

Weekly Management Coordination recurring-pattern cards can now open the canonical Task Center create form when history is narrowed to one accessible employee.

The GET link safely prefills employee, current week dates, reviewer and current draft/active Battle Plan when available, plus a bounded task title/description containing the transparent signal rule and matched weeks. GET never creates a task; canonical Task Center POST validation, ACL/RBAC, company isolation and Battle Plan linkage rules remain authoritative.

Aggregate company/unit patterns do not guess an assignee. The UI asks the manager to select one employee first.

Task Center now accepts bounded presentation-only GET prefill for `title` and `description` in addition to the already-supported assignee/reviewer/dates/Battle Plan fields.

- No DDL.
- No new task/decision/pattern entity.
- No automatic task creation.
- No hidden ranking or causal inference.
- Coordination contract: 0.6.0.

## Live acceptance

NOT RUN. Verify one-employee pattern action, aggregate-scope no-action behavior, Task Center prefill, POST validation, plan linkage, ACL/company isolation, and mobile layout.
