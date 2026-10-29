# local_kbacompany 1.1.0-rc6 — Position Indicators → Battle Plan

Build: `2026101222`

## Scope

This RC adds a Position-level indicator assignment workspace on top of the existing canonical Indicator Registry and Battle Plan indicator projection.

## Canonical flow

`Position → canonical indicator binding → versioned norm → employee assignment → weekly Battle Plan projection`

No KPI definition or fact is copied into the Battle Plan. Only weekly indicators are projected into a weekly Battle Plan.

## No schema change

The release reuses `local_kbacompany_stat`, `local_kbacompany_statbind`, `local_kbacompany_statnorm`, and existing Battle Plan projection services.
