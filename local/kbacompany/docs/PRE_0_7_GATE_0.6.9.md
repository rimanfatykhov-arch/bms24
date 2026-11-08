# BMS 0.6.9 — Architecture Stabilization / Pre-0.7 Gate

This release closes the 0.6.x platform-foundation sequence before Competency & People domain work starts.

## What the gate verifies

For one canonical BMS Company UUID the gate checks:

- canonical Company Context availability;
- Workspace projection storage availability;
- provider-neutral event outbox storage availability;
- entitlement snapshot storage availability;
- Company ↔ Workspace mapping state;
- Cloud projection freshness when mapped;
- entitlement runtime safety;
- provider-neutral outbox error state.

## Gate semantics

- `ready` — no warnings or blockers;
- `ready_with_warnings` — domain work may continue, but the platform diagnostic has non-blocking follow-up work;
- `blocked` — a platform contract required by the selected runtime mode is unusable.

An unmapped existing Company is not blocked: it remains in `legacy_compatibility` and is reported with a warning. A Company explicitly switched to `cloud_snapshot` is blocked when it has no usable entitlement snapshot.

The gate is diagnostic only. It does not change Workspace binding, entitlement mode, ACL, Company data, integration state or business data.

## No new business module

0.6.9 deliberately adds no Competency business model. After this gate, 0.7.0 can introduce the Competency Architecture on top of the stabilized platform contracts.
