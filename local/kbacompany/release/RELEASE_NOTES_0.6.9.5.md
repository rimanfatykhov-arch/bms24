# BMS 0.6.9.5 — Administrative Scale Strategy Foundation

Build: `2026092135`

## Purpose

Turn the existing BMS Administrative Scale into the strategy-to-execution bridge without creating parallel Strategy, KPI, Project, Task or Battle Plan engines.

## Delivered

- Administrative Scale methodology views: Long-term Goal, Goal, Organizational Policy, Plan, Program, Decision/Order, Ideal State, Statistic and Valuable Final Product.
- Execution-support views: Program Step, Project and Task.
- Plan fields are explicit: **Situation**, **Goal of this Plan**, and **Plan**.
- Long-term Goal can reference a published Owner Requirement package only; private owner/LPF material is not copied into strategy.
- Goal remains linked to the existing published BSC Strategic Goal; Organizational Policy remains linked to the existing published Policy.
- Statistic references the canonical BMS Indicator/Statistic object; no second KPI registry is created.
- Valuable Final Product may reference the canonical BMS Product object and is not treated as a statistic.
- Published Administrative Scale Task definitions can be sent to an existing draft Battle Plan. The current Task Center creates/reuses the canonical execution task; Battle Plan stores only its reference.
- Generated tasks retain the exact Administrative Scale task source revision plus the immediate management context and provide links back to the source.
- Strategy & BSC page exposes Administrative Scale readiness and a direct execution path to Battle Plan while keeping BSC as a separate representation.

## Compatibility

Existing published Administrative Scale records remain readable. There is no destructive migration. The new parent rules are applied when a new revision is saved: a Goal is now rooted under a published Long-term Goal, Program Step belongs to Program, and Project belongs to Program Step.

## Schema

Additive only on `local_kbacompany_adminitem`:

- `situation` TEXT nullable
- `plangoal` TEXT nullable

No `access.php` or `services.php` change.

## Out of scope

- Competency Architecture (remains 0.7.0).
- Automated strategy recommendations or AI evaluation of alignment.
- New task engine or Battle Plan engine.
- ERP/accounting actual ledger.
- Learning/enrolment changes.
