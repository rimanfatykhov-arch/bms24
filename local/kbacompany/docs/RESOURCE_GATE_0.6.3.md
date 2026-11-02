# BMS Resource Gate 0.6.3

Build: `2026091523`

## Purpose

Prevent a published BMS goal, programme or project from being treated as launch-ready until resource feasibility has been explicitly assessed.

## Seven dimensions

1. Money
2. People
3. Competencies
4. Time
5. Technology
6. Information
7. Infrastructure

## Canonical references

- Money: exact approved Budget revision for the same management target; if a gate pins a Financial Scenario, the budget must pin that exact scenario revision.
- People: canonical active Position; factual staffing remains in the existing Staffing placement bounded context.
- Competencies: stable Business Role UUID from the existing read-only role/competency projection; the gate never raises or writes an employee competency level.
- Information: published/effective Controlled Document revision.
- Technology / infrastructure / time: evidence-backed checks unless/until a separate canonical registry exists.

## Lifecycle

`draft -> submitted -> ready | blocked -> archived`

Editing a decided gate creates a new draft revision. A prior Ready remains effective while the amendment is draft/submitted. A later Blocked decision revokes that effective readiness.

## Ready invariant

Submission requires at least one check for every resource dimension. `ready` requires every required check to be `pass` or `waived`; a waiver requires explicit evidence.

## Ownership boundaries

Resource Gate stores assessment/evidence only. It does not execute payments, staffing changes, training/competency changes, procurement, bank actions or ERP writes. KBA Connect and KBA Corporate remain unchanged.
