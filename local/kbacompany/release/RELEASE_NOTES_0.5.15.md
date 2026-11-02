# BMS v0.5.15 — Stabilization / Weekly Management Acceptance

Build: **2026091513**

## Purpose

Final stabilization checkpoint for the 0.5 weekly management contour before 0.6.0 Financial Model.

## Delivered

- Bundles the planned 0.5.14 batch transition step: selected Battle Plans are submitted/activated explicitly, one by one through canonical `battle_plan_service::transition()`.
- Adds `Weekly Management Acceptance` as a schema-neutral read-model.
- Acceptance hard gates cover weekly-plan coverage, lifecycle readiness, remaining draft/submitted plans and canonical reference integrity.
- Indicator-configuration gaps, management decisions still required and overdue canonical Tasks are attention signals, not a second lifecycle.
- Aligns server lifecycle with readiness: reviewer/observer are revalidated for submit/approve/selfapprove.
- No acceptance status is persisted; fixing data or completing transitions recomputes the result immediately.

## Architecture

No new source of truth. No new tables. No new capabilities. No external-service change.

Canonical chain remains:

`Assignment -> Battle Plan -> Task -> Indicator -> Fact -> Trend -> Condition -> Manager decision -> canonical Task -> next fact`.

## Not claimed

Moodle PHPUnit, browser E2E and production upgrade are not claimed unless explicitly executed in the QA report.
