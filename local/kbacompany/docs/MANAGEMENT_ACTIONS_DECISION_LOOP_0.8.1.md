# BMS 0.8.1 — Management Actions & Decision Loop

## Purpose

Close the operational management loop without introducing a parallel decision, task or KPI engine.

Canonical chain:

`Indicator → Management Exception → Immutable Manager Decision → Canonical Task → Battle Plan / Control → Exact Follow-up Fact → Close or New Decision`

## Rules

- The loop is a read-model over existing canonical objects.
- A manager decision remains an immutable `stateevt` event.
- Execution remains the canonical BMS Task.
- Battle Plan remains the execution workspace.
- Follow-up uses the exact next-week fact already defined by the Indicator Management Cycle.
- Worsening or unchanged follow-up may require a new decision when no later decision exists.
- An improving follow-up does not claim that the action caused the improvement.
- No schema, ACL, KPI engine, task engine or incident engine is added.
