# BMS 0.6.9.9 — Business Model CRUD Foundation

Build: `2026092139`

## User-facing changes
- Business Model Canvas 2.0 now uses BMS CRUD: ListView → record header → Canvas View → Block View → Item Detail/Edit.
- One sticky note is one `BMCI-*` record.
- AS-IS and TARGET are edited separately.
- AS-IS records require FACT / DATA / HYP / OPEN classification; FACT requires evidence/source and HYP requires a test criterion.
- All nine BMC blocks include methodology hints, key questions and typical mistakes.
- Owner REQ links are selected from the exact published requirement package pinned to the BMC revision.
- Position-first accountability is available on Canvas items.
- Comments and item revision history are available without rewriting record content.
- Legacy textarea Canvas is read-only compatibility.

## Revision safety
- Root `BMC-*` revisions are snapshot boundaries.
- Published snapshots are immutable.
- New root revisions inherit item state transactionally.
- Stable Codes remain human references; UUID remains canonical identity.

## Boundaries
No GAP/Change/KPI/Project engine is introduced. Financial Model, BMS Indicator, Administrative Scale, Program/Project, Task and Battle Plan remain canonical in their existing domains.

## Upgrade
Additive schema upgrade savepoint `2026092139`.
