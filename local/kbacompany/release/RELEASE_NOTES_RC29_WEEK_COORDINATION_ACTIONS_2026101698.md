# BMS24 1.1.0-rc29 / build2026101698

Date: 2026-10-19

## Summary

Weekly Management Coordination is now action-first: each agenda item exposes contextual actions that route into existing canonical BMS24 controllers.

## Product changes

- Submitted Battle Plans: direct **Review plan** action opens the canonical Battle Plan review tab.
- Indicator decision / critical KPI items: direct **Open KPI** action opens the selected Battle Plan indicator tab.
- Overdue execution: direct **Open tasks** action opens the selected Battle Plan tasks tab.
- Missing Battle Plan: direct **Create Battle Plan** action uses the canonical coverage action URL.
- Draft/active plans that allow management follow-up: **Create task** opens Task Center create with bounded GET prefill for employee, reviewer, week and Battle Plan link.
- Agenda items expose **Employee** links to the existing employee card.
- No action performs a write from the coordination screen itself. Target controllers remain authoritative for permissions, validation and lifecycle.

## Architecture / safety

- No new tables, statuses, decision registry or duplicate task model.
- No DDL.
- ACL/RBAC, privacy and company isolation remain enforced by canonical target controllers.
- `battle_plan_week_coordination_service` contract bumped to 0.2.0.

## Live acceptance

NOT RUN. Verify each contextual action as manager/reviewer, especially Review plan and Task Center prefill.
