# 0.8.5 — Management Cycle Templates & Cadence

- Added canonical Management Cycle instances and cadence checkpoints.
- Added standard templates: Week, Month, Quarter, Year, Project, Owner Advisor 4×90.
- Added parent-cycle relationship for Year → Quarter → Month → Week cascade.
- Added decision owner and cadence curator roles on each cycle.
- Added canonical Indicator selection at cycle launch; no parallel KPI engine.
- Added read-only projection of the owner’s Battle Plans that fall inside the cycle period.
- Added checkpoint workflow and Curator Workspace with launch/overdue/closure attention.
- Owner Advisor template requires 1–3 canonical indicators and creates four management checkpoints.
- Added tables `local_kbacompany_mcycle` and `local_kbacompany_mcyclecp`.
- Privacy metadata/export/delete support extended for cycle data.
