# BMS24 1.1.0-rc29 / build2026101701

Date: 2026-10-19

## Summary

Weekly Management Coordination now includes a bounded 4/8-week management history for the selected company/unit scope or one accessible employee.

## Product behavior

- History depth is explicitly selectable: 4 or 8 weeks.
- Scope is the selected company/unit, or one employee visible through the canonical management overview.
- Each week shows task productivity, KPI effectiveness and data coverage separately.
- Management-task done/open counts and factual attention signals are shown beside the week.
- Historical points link to the canonical Battle Plan management overview for that week.
- No composite employee score or ranking is introduced.

## Architecture

- `battle_plan_week_coordination_service` contract: 0.4.0.
- Historical values are recomputed from `battle_plan_management_overview_service`.
- No snapshot/history table is added.
- No write path is added.
- Existing ACL/RBAC/company isolation of source read-models remains authoritative.
- History is bounded to 4 or 8 weeks to keep the read-model predictable.

## Schema / security

- No DDL.
- No new lifecycle/status/entity.
- No ACL/RBAC change.
- No company-isolation change.

## Live acceptance

NOT RUN. Verify 4/8-week switch, company/unit scope, employee scope, empty weeks, active current week, historical links and mobile layout.
