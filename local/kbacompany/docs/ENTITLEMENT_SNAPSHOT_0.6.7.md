# BMS v0.6.7 — Entitlement Snapshot Client / Cloud Contract

## Purpose
Prepare shared Moodle/BMS Management to consume versioned entitlement snapshots from the future standalone BMS Cloud without a synchronous Cloud call on every page request.

## Runtime model
- BMS Cloud remains the entitlement owner.
- Moodle stores a validated local last-known-good snapshot only.
- Existing companies remain `legacy_compatibility` unless explicitly switched to `cloud_snapshot`.
- Uploading a snapshot does not switch enforcement mode automatically.
- In `cloud_snapshot`, a valid local snapshot is authoritative for `mgmt.*`.
- After `valid_until`, `mgmt.*` can use last-known-good for a local 24-hour continuity window; after that the resolver fails closed.
- Workspace suspension always denies before snapshot evaluation.

## Snapshot schema v1.0
Required fields: `schema_version`, `workspace_uuid`, `company_uuid`, `snapshot_revision`, `catalog_version`, `resolver_version`, `generated_at`, `valid_until`, `entitlements`, `snapshot_hash`.

`entitlements` is a map keyed by `mgmt.*` code. Values are normalized to `allowed`, `state`, and scalar/null `value`.

`snapshot_hash` is SHA-256 over the normalized JSON object without the hash field, in fixed field order and sorted entitlement-code order. It is an integrity check, not a substitute for service authentication.

## Restricted Cloud API
A new service `local_kbacompany_cloud_v1` is disabled by default and restricted to explicitly authorised service users with `local/kbacompany:cloudsync`.

Functions:
- contract
- apply entitlement snapshot
- entitlement snapshot status

## Storage
`local_kbacompany_entsnap` stores snapshot history. One active row per Company is maintained by the application service; older snapshots become `superseded`.

## Safety
No existing Company is migrated to Cloud enforcement by the database upgrade. No pricing, subscription tables, raw secrets, Bitrix identifiers, emails, phones or ERP actuals are stored in the snapshot cache.
