# BMS24 Bitrix24 Provider Extension — 0.9.2

## Purpose

0.9.2 gives the existing KBA Connect / `local_bitrixsync` transport a provider-neutral BMS contract without moving Bitrix24 REST ownership into `local_kbacompany`.

## Ownership boundary

- BMS / `local_kbacompany`: canonical Company/Employee/Position management state, identity mappings, provider-neutral events and the new provider API.
- KBA Connect / `local_bitrixsync`: Bitrix24 REST/webhook/OAuth transport and CRM reconciliation.
- Bitrix24: CRM operational state.

`local_kbacompany` does **not** store Bitrix24 webhook/OAuth secrets and does not call Bitrix24 REST directly.

## Service

External service: `local_kbacompany_bitrix_v1`, disabled by default and restricted-users-only.

Functions:

- `local_kbacompany_bitrix_contract`
- `local_kbacompany_bitrix_readiness`
- `local_kbacompany_bitrix_manifest`
- `local_kbacompany_bitrix_mappings`
- canonical Public API v1 Company / Employees / Positions functions in the same service

## Readiness

Readiness reports:

- scoped Bitrix24 Company identity for one environment, or preserved `legacy` Company mapping compatibility;
- whether the existing KBA Connect consumer class is detectable;
- company/position/indicator scoped mapping counts;
- pending/error legacy command counts;
- provider-neutral event outbox summary.

`remote_verified=false` is intentional: local mapping/readiness is not a claim that the Bitrix24 portal is reachable.

## Reconciliation manifest

The manifest exposes hashes/counts for canonical BMS snapshots:

- Company;
- Employees (including contact fields only under existing management permission checks);
- Positions.

The actual payloads remain owned by `local_kbacompany_public_v1`; 0.9.2 does not create a second CRM DTO store.

## Legacy compatibility

Existing unscoped Bitrix24 Company mapping remains available as the synthetic `legacy` environment. Legacy child/user mappings are not enumerated by the new API because the old rows do not provide a reliable Company UUID security scope. Existing KBA Connect behavior is preserved.

## Not included

- no second Bitrix24 REST client;
- no direct webhook/OAuth secret storage in BMS;
- no CRM deal ingestion;
- no automatic indicator fact import from Bitrix24;
- no changes to enrolment semantics;
- no schema migration.
