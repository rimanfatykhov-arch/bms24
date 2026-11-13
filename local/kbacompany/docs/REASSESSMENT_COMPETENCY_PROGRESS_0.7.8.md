# BMS 0.7.8 — Reassessment & Competency Progress

## Purpose
Connect approved competency assessments, completed development actions and the current published position profile into a traceable competency progress view.

## Rules
- Progress is calculated only from approved formal assessments.
- Completing a development action or Moodle course never changes competency attainment directly.
- Reassessment is recommended when completed development actions exist after the latest approved assessment or the published position profile changed.
- No arbitrary time-based competency expiry is introduced.
- Reassessment creates a new normal Assessment Engine draft and follows the same approval boundary.
- All reads remain company-scoped before fetch.

## UX
- BMS24 Record Header and employee-local navigation.
- KPI cards for approved assessments and trend distribution.
- Dense progress table with first/last approved level, delta and trend badges.
- Employee Competency Profile is aligned with the same navigation and status presentation.
