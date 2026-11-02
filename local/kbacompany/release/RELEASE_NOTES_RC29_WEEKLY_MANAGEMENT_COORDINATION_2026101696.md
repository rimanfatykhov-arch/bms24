# BMS24 1.1.0-rc29 / build2026101696

Date: 2026-10-19

## Summary

First product release after release-pipeline stabilization: **Weekly Management Coordination**.

Managers now have one action-first weekly agenda that composes the existing Battle Plan management overview, readiness and coverage read-models. It surfaces submitted plans, readiness blockers, missing plans, management decisions, overdue tasks and critical indicator exceptions; shows task productivity / metric effectiveness / data coverage separately; and provides unit-level management-task feedback.

## Architecture

- New schema-neutral `battle_plan_week_coordination_service`.
- New manager page `battle_plan_week_coordination.php`.
- No new decision/status/task/indicator entity.
- No database schema changes.
- Existing Battle Plan / Task Center / Indicator ACL and lifecycle remain authoritative.
- All actions are links into canonical controllers.

## Navigation / UI

- Added Weekly Coordination to Management → Planning navigation.
- Added Weekly Coordination to Battle Plan tools.
- Reuses canonical BMS24 UI Kit filterbar.
- Responsive action KPI cards, attention agenda and unit cards.

## QA expectation

- PHP lint all plugin PHP files.
- RU/EN key parity.
- upgrade checkpoint 2026101696.
- release documentation guard.
- ZIP integrity and SHA256.

## Live acceptance

NOT RUN at build time. Verify manager ACL, week/unit filters, agenda links, mobile layout and data agreement with Overview / Readiness / Coverage on Moodle.
