# BMS24 1.1.0-rc29 / build2026101700

Date: 2026-10-19

## Summary

Canonical navigation documentation plus previous-week management follow-up in Weekly Management Coordination.

## Navigation documentation

- Added `docs/BMS24_NAVIGATION_STANDARD.md` as the canonical L1/L2/L3/L4 navigation standard.
- Added the standard to `docs/MASTER_INDEX.md`.
- Defines sidebar vs contextual quick-nav vs domain navigation vs breadcrumbs, active-state, bounded context propagation, ACL rules, duplicate prevention, responsive behavior, navigation matrix and acceptance checklist.

## Weekly coordination follow-up

- `battle_plan_week_coordination_service` contract 0.3.0.
- Reads the previous week's canonical Battle Plan overview using the same ACL/company scope.
- Shows live canonical manual-task status attached to previous-week Battle Plans: total, done, open and up to three currently open task names.
- Joins the current accessible plan for the same employee/position and shows factual week-over-week productivity/effectiveness/data-coverage deltas.
- Links only to existing previous-week task tabs and current Battle Plans.
- Explicitly states that post-action movement is observation, not proof of causality.

## Safety

- No DDL.
- No new task/decision/status registry.
- No write path added to coordination.
- Existing Task/Battle Plan ACL, lifecycle and company isolation remain authoritative.

## Live acceptance

NOT RUN. Verify navigation standard behavior and previous-week follow-up on real company data.
