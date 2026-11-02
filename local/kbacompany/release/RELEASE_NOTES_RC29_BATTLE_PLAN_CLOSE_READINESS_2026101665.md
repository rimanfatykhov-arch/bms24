# BMS24 1.1.0-rc29 — build 2026101665

## Battle Plan: close readiness

- Active Battle Plans now expose **Close** only when the canonical lifecycle can actually close the plan.
- Unfinished tasks that are neither completed/cancelled nor explicitly carried into the next draft plan are shown as closing blockers.
- The close confirmation route also renders the blocker instead of presenting a submit action that the server would reject.
- Empty week outcome and problematic/no-data metrics are shown as non-blocking advisories only; they do not change lifecycle rules.
- Existing rollover, ACL/RBAC, privacy and company-isolation contracts are unchanged.

## Technical impact

- No DDL or schema changes.
- No new status or workflow.
- No capability changes.
- Upgrade savepoint only.
