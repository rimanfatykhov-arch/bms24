# Management Exception Cycle — v0.5.9

## Purpose

v0.5.9 closes the management loop inside the existing **Management Exceptions** queue without creating an Incident, ActionStatus or Effect registry.

Read-model chain:

`Indicator / statbind -> analytics -> stateevt -> canonical Task -> Battle Plan -> next weekly statval`

The queue answers five operational questions for one current indicator binding:

1. Is a management decision required now?
2. Has the decision already been converted into canonical execution?
3. Is the canonical Task in progress or overdue?
4. Which accessible Battle Plan currently carries the work?
5. What happened to the exact next weekly fact after the action: improvement, worsening or no change?

## Canonical boundaries

- Indicator: `local_kbacompany_stat`.
- Fact history: append-only `local_kbacompany_statval`.
- Manager decision/context: immutable `local_kbacompany_stateevt`.
- Execution: `local_kbacompany_task`.
- Task progress: `local_kbacompany_taskcheck`.
- Weekly execution projection: `local_kbacompany_bplan` + `local_kbacompany_bplanitem`.

v0.5.9 stores none of the management-cycle status itself.

## Follow-up semantics

The system compares only the exact adjacent weekly observation after an action:

`fact(W) -> fact(W+7)`.

Direction of the indicator is respected when classifying the observation. The comparison is descriptive and is never presented as proof that the Task caused the change.

A worsening or unchanged next fact becomes a current attention reason only when there is **no newer manager decision** at or after the follow-up week. This prevents the queue from demanding a decision that has already been made.

## Battle Plan linking

For position-bound indicators the queue resolves accessible Battle Plans for the current week. Task-in-plan markers and historical linked-plan references are shown only when canonical Task ACL allows the Task itself to be viewed.

No Battle Plan is created automatically by this read-model.

## Storage impact

Schema-neutral. No new DB tables or fields. Upgrade 2026091507 contains only the plugin savepoint.
