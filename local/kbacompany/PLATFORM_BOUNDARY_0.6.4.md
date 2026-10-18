# BMS Platform Boundary & Company Isolation Hardening — v0.6.4

Build `2026092125`.

## Purpose

v0.6.4 prepares `local_kbacompany` for the BMS24 platform architecture without moving Cloud Control Plane or ERP ownership into Moodle. The release is intentionally infrastructure-focused and preserves existing user-facing BMS behavior.

## Platform boundary

- Moodle / `local_kbacompany` owns the BMS Management model.
- Moodle / KBA Corporate owns learning execution.
- BMS Cloud owns Workspace, commercial entitlements, application provisioning, domains, releases and infrastructure lifecycle.
- Odoo owns operational ERP execution and transaction actuals.
- Bitrix24 remains the current CRM provider; provider-specific REST remains outside the BMS domain layer.

## Company isolation

`company_uuid` is now the explicit platform security boundary. A new `company_context_service` is the single boundary used by BMS domain/application services to resolve an authorised Company context. Existing authorization semantics are preserved by delegating to the mature `company_service::company_context()` implementation.

All direct calls from `classes/local` were migrated to `company_context_service::context()` except the boundary implementation itself. A company-scope heuristic scan was also run over database reads in BMS services; the remaining reported cases are either explicitly company-scoped through composed conditions or internal integration-outbox operations keyed by globally unique command UUID.

## BMS Cloud Workspace projection

A new additive table `local_kbacompany_cloudmap` stores only the local projection required to bind a Company UUID to an external Workspace UUID:

- `companyuuid`
- `workspaceuuid`
- `cloudstatus`
- `entitlementmode`
- `snapshotrevision`
- sync/audit timestamps and actor IDs

Moodle does not become the owner of Workspace lifecycle. Existing companies have no row after upgrade and therefore remain in legacy-compatible mode.

## Entitlement boundary

`entitlement_service` introduces the future commercial capability check without changing current access:

- unmapped / `legacy_compatibility` → allowed;
- `suspended` → denied;
- `cloud_snapshot` without a valid future snapshot implementation → denied (fail closed).

Entitlement is explicitly separate from Moodle capabilities and BMS business rules. New business code must not branch on edition names.

## Provider-neutral event envelope

`platform_event_envelope` introduces a provider-neutral event shape for future BMS Cloud / Odoo / Bitrix integrations:

- schema version;
- event UUID;
- event type;
- Company UUID;
- entity type / UUID / revision;
- occurrence time;
- stable idempotency key;
- JSON payload.

This release does not replace the existing KBA Connect outbox. It creates the contract used by the next integration release.

## Platform diagnostics

Management users can see additional diagnostics on the Employees page:

- Workspace mapping state;
- entitlement mode;
- entitlement reason code.

A new read-only web-service function `local_kbacompany_platform_context` exposes management-only platform diagnostics for authenticated service consumers.

## Compatibility

- Existing companies retain current functionality after upgrade.
- No company is auto-mapped to BMS Cloud.
- No entitlement is revoked by the upgrade.
- KBA Connect behavior is unchanged.
- KBA Corporate learning behavior is unchanged.
- No Odoo connection is introduced.
- No Cloud Registry business tables are created inside Moodle beyond the single Workspace projection mapping table.

## Non-goals

v0.6.4 does not implement:

- BMS Cloud Registry;
- subscriptions / billing;
- entitlement snapshot transport;
- Odoo provisioning;
- provider-neutral durable outbox persistence;
- Cloud domains / releases / backups;
- new competency features.
