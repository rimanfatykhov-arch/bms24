# BMS24 RC29 — Canonical Filter UI (build 2026101692)

## What changed
- Promoted the compact two-column filter pattern into the canonical BMS24 UI Kit `ui_filterbar`.
- Added reusable typed `ui_fieldgrid` fragment.
- Migrated Battle Plan management overview, coverage, readiness and weekly acceptance filters to the shared UI Kit component.
- Desktop uses two columns; mobile collapses to one column; filter actions remain on their own row.
- Create-plan selection/settings keep the same visual grid standard while retaining their distinct workflow semantics (`Continue / Cancel`, not filter `Apply / Reset`).

## Boundaries
Presentation-only. No DB schema, ACL/RBAC, lifecycle, privacy, company-isolation or filter-semantic changes.
