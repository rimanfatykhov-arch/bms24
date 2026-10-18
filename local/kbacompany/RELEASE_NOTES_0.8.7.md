# local_kbacompany 0.8.7 — Управленческий разбор A3

Build: 2026092406

## Scope
- A3 lives inside a canonical Management Cycle.
- 9 blocks: problem/context → current state → target state → GAP → root-cause analysis → countermeasures → implementation plan → result verification → standardization.
- Optional link to a canonical Indicator from the cycle, with baseline/target/repeat fact snapshots.
- Countermeasure execution remains canonical Tasks + Battle Plans; no parallel task/KPI/problem engine.
- Repeat fact is explicit: captured from the Indicator Registry on command or entered by the user; never inferred.
- Closing A3 requires a result conclusion, but does not modify indicator facts or close tasks.
- Standardization in 0.8.7 is a proposal only; process/policy/document/training changes remain separate canonical flows.

## Storage
New company-scoped table: `local_kbacompany_a3review`.

## Not run
Live Moodle upgrade, PHPUnit, Behat/E2E, production acceptance.
