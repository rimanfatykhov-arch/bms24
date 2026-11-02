# BMS v0.5.5 — Management Exceptions

## Purpose

The Management Exceptions read model answers one management question: **what requires attention now?**

It is not a new workflow, incident register or KPI registry. It projects current exceptions from canonical business data.

## Sources

- canonical Indicator: `local_kbacompany_stat`;
- append-only plan/fact: `local_kbacompany_statval`;
- business binding: `local_kbacompany_statbind`;
- versioned norm: `local_kbacompany_statnorm`;
- versioned condition policy: `local_kbacompany_statpolicy`;
- derived trend/events/condition read-models;
- immutable manager decision: `local_kbacompany_stateevt`;
- canonical execution: `local_kbacompany_task`.

## Identity rule

The queue key is the **current indicator binding**. If the same binding has several simultaneous reasons, BMS shows one queue row with several badges. It does not create one record per reason.

## Attention levels

- **Critical** — Non-Existence, Danger, critical indicator without fact, or overdue canonical execution.
- **High** — Emergency, Power Change, current fact outside norm, explicit critical-policy configuration gap, or confirmed attention state without Task.
- **Medium** — worsening trend, adverse reversal/extreme, critical norm gap, or attention condition awaiting manager confirmation.

These are semantic mappings, not hidden numerical thresholds.

## Direction awareness

An adverse event depends on indicator direction:

- `growth is good`: reversal down or new minimum is adverse;
- `decline is good`: reversal up or new maximum is adverse;
- neutral direction does not receive an automatically adverse event classification.

## Execution boundary

The queue does not create an execution object by itself. The manager opens the existing Condition workspace, confirms/contextualizes the state and may create one canonical Task. If that Task already exists but is not visible to the current user, the exception queue does not reveal its title or direct link.

## Storage decision

On-read computation is sufficient because all current causes are derivable from canonical/versioned data and execution state. A separate exception-storage table would introduce duplicate lifecycle and stale-state risk, so v0.5.5 intentionally remains schema-neutral.
