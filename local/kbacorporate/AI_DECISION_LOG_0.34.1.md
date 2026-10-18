# AI Decision Log v0.34.1

## Use the existing economic registry as the source of truth

The dashboard does not introduce a parallel KPI store. It reads `local_kbacorp_econ_ind` and `local_kbacorp_econ_val`, preserving the data model introduced in `v0.33.4`.

## Standard Moodle charts instead of an external library

Line and bar charts use Moodle core chart classes. This avoids CDN dependency, reduces supply-chain risk and follows the active Moodle theme and accessibility behaviour.

## Read-only projection

`v0.34.1` does not edit values from the dashboard. Data entry remains in the registry forms, which already enforce validation, history and company isolation.

## Limit the visible KPI set

A maximum of 8 indicators is rendered at once, with 6 selected by default. This prevents an unbounded page of charts and keeps pilot performance predictable.

## Direction-aware interpretation

For `up`, an increase is positive. For `down`, a decrease is positive. For `target`, change is neutral until a dedicated target model exists. This prevents generic green/red arrows from misrepresenting the KPI semantics.

## Conservative currency handling

Money in different currencies is not combined or compared. The dashboard does not perform implicit conversion because no exchange-rate source or valuation date is defined.

## Audit values are explicit

The audit snapshot compares values directly linked to audits. The dashboard does not silently infer the nearest economic period as an audit value.

## Older audit context expands only implicit date ranges

When the user opens an older audit and has not chosen dates, linked values are included in the displayed range. Explicit user date filters are never overridden.

## No causality claim

The dashboard visualises economic movement and audit context. Causal interpretation remains outside `v0.34.1` and is addressed cautiously in the later influence-analysis release.
