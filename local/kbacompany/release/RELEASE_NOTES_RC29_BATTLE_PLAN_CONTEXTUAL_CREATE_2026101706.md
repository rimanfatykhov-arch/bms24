# BMS24 1.1.0-rc29 / build2026101706

## Battle Plan contextual create UX

Live review of Weekly Management Coordination showed that the action labelled “Create Battle Plan” used the Battle Plan list route without `view=create`. Build1706 fixes the canonical creation flow.

### Changes
- Missing-plan coverage actions now use `battle_plan.php?view=create&contextual=1` with the known employee, position and week.
- Contextual create shows employee, position and week as a read-only summary instead of forcing the manager through the context selector again.
- “Change employee or position” deliberately leaves contextual mode and reuses the normal creation flow.
- The known week is posted as a hidden value; reviewer/observer/template/norm remain explicit form choices.
- Before rendering contextual create, the canonical service checks whether the employee/position/week plan already exists; if so, BMS24 opens that plan instead of presenting another create form.
- POST creation remains canonical and idempotent through the existing Battle Plan lock/ensure logic. GET never creates a plan.

### Security / architecture
- No DDL.
- No new Battle Plan entity or lifecycle.
- Existing `form_context`, ACL/RBAC and company isolation remain authoritative.
- No automatic reviewer or assignee inference was added.

### Live acceptance
NOT RUN. Verify from Weekly Coordination: missing plan → contextual create, locked employee/position/week, Change context, reviewer validation, and existing-plan redirect.
