# BMS24 RC29 — build 2026101654

## Battle Plan — Metric Progress & Actions UI

Presentation-only stabilization of metric cards:

- shows actual-to-period-plan percentage when both values are available and non-negative;
- adds a capped visual progress bar while keeping the numeric percentage uncapped;
- makes trend more scannable with a directional marker and quality state;
- changes ambiguous edit actions to contextual labels for fact and period plan;
- compacts daily aggregation helper text and keeps the full explanation in a tooltip;
- keeps metric facts, plan persistence, performance status, ACL, privacy and company isolation unchanged.

No database schema change.
