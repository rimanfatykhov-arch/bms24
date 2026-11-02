# BMS 0.6.9.7 — Balanced Scorecard Management

Build: `2026092137`

## Purpose
Turn the existing Strategy/BSC domain into an operational management workspace without creating parallel goal, KPI or task registries.

## Canonical reuse
- Strategic goals: existing `local_kbacompany_stratgoal`.
- Indicators and fact history: existing `local_kbacompany_stat` / `local_kbacompany_statval`.
- Goal↔indicator relation: existing `local_kbacompany_statbind` with `contextkind=strategygoal`.
- Targets/ranges: existing versioned `local_kbacompany_statnorm`.
- Accountability: BMS Position; current employee is a projection of active Position Assignment.
- Execution: existing Administrative Scale → canonical BMS Task → Battle Plan.
- Causal map: existing `local_kbacompany_goallink`.

## BSC workspace
`/local/kbacompany/strategy.php?uuid=<company_uuid>` now has three working views:
1. BSC overview — four perspectives, goal health, accountability, indicator coverage and execution summary.
2. Strategic map — goals grouped by perspective plus the existing acyclic causal links.
3. Setup — versioned strategy cycles, strategic goals and causal links.

## Goal health
Health is a read model, not a stored fact:
- green: every evaluated indicator is within its explicit target/range;
- yellow: a non-critical indicator is outside its explicit target/range;
- red: a critical indicator is outside its explicit target/range;
- no data: no factual observation or no explicit norm;
- draft: current goal revision is draft.

No hidden percentage corridor is introduced. Trend is derived by the existing `indicator_trend_service` from canonical fact history.

## Accountability
Strategic goals keep `ownerpositionuuid` as the accountable organizational role. The UI resolves active position assignments to show the current employee(s) without changing goal ownership when people change.

## Execution traceability
A published Administrative Scale branch can explicitly source a strategic goal (`sourcekind=strategic_goal`). The BSC goal card reads that branch and its descendants, then summarizes canonical tasks created from Administrative Scale task items. Execution remains in Battle Plan.

## Stable codes
Ownership Intent, Strategy and Strategic Goal codes are generated server-side using the reusable stable-code allocator:
- `OWN-0001`
- `STR-0001`
- `GOAL-0001`
Existing historical codes are preserved on edit/revision; UUID remains canonical identity.

## Non-goals
- no second KPI registry;
- no second task manager;
- no manual strategic-progress percentage;
- no automatic recommendation/AI scoring;
- no changes to KBA Corporate, learning ownership, competencies, ERP actual or BMS Cloud boundaries.
