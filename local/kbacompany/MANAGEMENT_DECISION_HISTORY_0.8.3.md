# Management Decision History & Learning — 0.8.3

## Purpose
Create a reusable management-learning view from existing canonical management history without introducing a second decision or knowledge engine.

## Sources
- `local_kbacompany_stateevt` — append-only manager decisions.
- canonical BMS Tasks sourced by condition events.
- existing Indicator Registry bindings and metrics.
- existing Battle Plan links exposed through the canonical execution history.

## Rules
- All reads are company-scoped.
- History is read-only and schema-neutral.
- A repeated case means multiple decisions exist for the same indicator/context binding.
- Recurrence does not prove that root cause was identical.
- Linked actions are shown only through existing Task ACL projections.
- No effectiveness or causality claim is inferred from recurrence or later metric movement.
