# BMS v0.6.8 — Cloud Workspace Mapping + Diagnostics

Build: `2026092129`

## Purpose

This release turns the v0.6.4 Workspace placeholder into a stable, versioned BMS Cloud projection contract without moving Cloud ownership into Moodle.

## Added

- Stable Company UUID ↔ Workspace UUID binding. An existing Company cannot silently rebind to a different Workspace.
- Monotonic `projection_revision` to reject stale Cloud projection updates.
- Provider-neutral runtime diagnostics: `cloud_status`, `erp_application_state`, `integration_health`, `snapshot_revision`, `last_sync`, `sync_freshness`.
- Restricted Cloud API methods for Workspace contract, projection apply and status.
- Manager diagnostics now expose Cloud Workspace runtime state in the existing Employees integration diagnostics area.
- Regression-test source for stable binding, monotonic revisions and provider-neutral runtime diagnostics.

## Ownership boundary

BMS Cloud remains owner of Workspace lifecycle, ERP application lifecycle and integration health. Moodle stores only a local projection/cache required by BMS Management runtime and diagnostics. No Odoo IDs, Bitrix IDs or Cloud infrastructure secrets are stored in this projection.

## Rollout safety

- The Cloud API service remains disabled by default and restricted to explicitly authorised users.
- Existing Companies remain `legacy_compatibility` unless an authorised Cloud projection explicitly changes entitlement mode.
- Applying Workspace diagnostics does not provision ERP, change DNS, create subscriptions or execute integrations.
- Production deployment/staging upgrade were not executed while building this package.
