# Management Cycle Templates & Cadence — 0.8.5

## Purpose

BMS now has one canonical Management Cycle wrapper for recurring management cadence. Templates configure rhythm; they do not introduce parallel KPI, task, meeting, or Battle Plan engines.

## Standard templates

- Week — launch, mid-week control, closure.
- Month — launch, two reviews, closure.
- Quarter — launch, mid-period review, closure.
- Year — launch, Q1/Q2/Q3/Q4 reviews, closure.
- Project — kickoff, mid-period review, closure.
- Owner Advisor 4×90 — four management sessions in one monthly period; 1–3 canonical indicators.

## Roles

- Decision owner — owns management decisions and business outcome.
- Curator — owns cadence: launch, checkpoints, overdue reviews, closure readiness.
- BMS — stores cycle structure, links canonical indicators, projects Battle Plans for the cycle period, and preserves history.

Curator does not become the legal/organizational superior of the owner and does not receive decision rights automatically.

## Hierarchy

Cycles may reference a parent cycle to support Year → Quarter → Month → Week cascades. This is a planning relationship, not a new task hierarchy.

## Canonical boundaries

- Indicators stay in Indicator Registry.
- Tasks stay canonical BMS Tasks.
- Weekly execution stays in Battle Plans.
- Checkpoint completion does not complete tasks or write indicator facts.
- Advisory template is a Management Cycle template, not a separate service engine.
