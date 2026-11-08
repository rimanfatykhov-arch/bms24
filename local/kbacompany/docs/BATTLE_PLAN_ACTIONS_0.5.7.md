# Battle Plan Indicator Management Action — v0.5.7

## Purpose

Close the weekly management loop directly from a canonical position-indicator row without creating a second action/task registry.

```text
Battle Plan week W
  -> canonical position indicator binding revision
  -> historical fact/norm/trend/condition snapshot as of W
  -> immutable manager condition event
  -> canonical local_kbacompany_task(sourcekind=condition)
  -> existing local_kbacompany_bplanitem reference to the same Task
```

## Historical boundary

The action endpoint never trusts the condition/period supplied by the browser. It reloads the selected Battle Plan and recomputes `battle_plan_indicator_service::projection()` for the plan week. The governance event pins the exact `statbind` revision and the analytical fact period. If no fact exists at all, the decision is anchored to the Battle Plan week. Future facts cannot leak into a historical plan action.

## Execution boundary

The plan owner is the canonical task assignee; reviewer and observer are taken from the Battle Plan. Client input cannot change those identities. The Task uses `sourcekind=condition` and the immutable event UUID as `sourceuuid`; the plan only receives an existing `bplanitem` reference.

## Idempotency

An exact repeated submission for the same binding/period/condition/comment reuses the latest identical event. `ensure_from_condition_event()` and `add_task()` then reuse the canonical Task/reference. If execution was explicitly soft-deleted, its tombstone is not resurrected: a later management attempt creates a new governance event/source.

## Compatibility

Legacy pinned quota condition overrides remain isolated compatibility behavior. v0.5.7 management actions are available only for canonical non-compatibility `statbind` rows.

## Schema

Schema-neutral. No new tables, fields, permissions or external services.
