# BMS v0.5.13 — Architecture snapshot

## Management week readiness

v0.5.13 adds a schema-neutral readiness read-model for existing weekly Battle Plans.

Canonical sources remain unchanged:

- `local_kbacompany_bplan` — weekly plan lifecycle;
- `local_kbacompany_bplanitem` — references to canonical/provider tasks;
- company employee directory — reviewer validity;
- `position/orgnode` — organisational projection;
- canonical position indicators and historical norms — advisory configuration quality.

Readiness is not stored and does not create a second lifecycle.

Hard blockers mirror the existing `battle_plan_service::transition()` contract only. Indicator/norm gaps are advisory and do not silently change transition rules.
