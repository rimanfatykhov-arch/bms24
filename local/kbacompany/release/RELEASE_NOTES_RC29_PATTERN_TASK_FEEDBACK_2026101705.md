# BMS24 1.1.0-rc29 / build2026101705

## Pattern → live Task feedback

Weekly Management Coordination now shows the latest canonical Task Center task created for a recurring management pattern and reads its live status through the existing task visibility boundary.

### Behavior

- One-employee pattern action descriptions now include a stable BMS24 trace marker.
- Coordination resolves only visible manual tasks matching that marker and selected assignee.
- The latest linked task is shown directly on the recurring-pattern card with a canonical Task Center link and live status.
- Active linked tasks suppress the duplicate-create prompt.
- When the latest linked task is done/cancelled and the factual pattern still recurs, a new task may be created.
- A bounded legacy-title compatibility path can surface build1704-created tasks where possible.

### Architecture / safety

- No DDL.
- No pattern-action table/entity.
- No automatic task creation.
- Trace marker is not authorization; Task Center projection ACL/RBAC/company isolation is authoritative.
- No ranking or causal inference.

### Live acceptance

NOT RUN. Verify one-employee pattern → create task → return to Coordination → linked task/status → transition task → refreshed live status.
