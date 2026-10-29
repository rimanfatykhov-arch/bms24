# local_kbacompany 1.1.0-rc16 — Metrics 2.2

Build: `2026101236`

## Scope

This release consolidates the BMS24 metric period model without introducing a second KPI store.

- Canonical metric plan/fact remains in `local_kbacompany_statval`.
- Versioned norms remain in `local_kbacompany_statnorm` and are resolved by effective period.
- Position metric bindings remain in `local_kbacompany_statbind`.
- Battle Plan reads the employee position's weekly metric projection; it does not copy metric definitions or history.

## Period status

A schema-neutral read model derives exactly four user-facing statuses:

- Within target / В норме
- Risk / Риск
- Critical / Критично
- No data / Нет данных

Target precedence is explicit: the exact period plan is used first; if it is missing, the effective norm is used.
There are no hidden percentage thresholds. An out-of-target fact is Risk; it becomes Critical only when the position binding is explicitly critical or the canonical trend is worsening.

## Battle Plan

The weekly Battle Plan metric table now shows:

- period plan;
- effective norm;
- fact;
- variance;
- derived status;
- trend;
- existing management condition/actions.

A status summary is displayed above the table.

## Metric drill-down

Metric history now includes period status next to plan/fact/variance, and the current metric card explains the status rule.

## Storage / compatibility

No DB schema, capability, route or privacy storage changes.
