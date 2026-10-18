# KBA Corporate ERP API — Production Hardening v0.40.0

**Build:** `2026091009`  
**Contract major:** `1`  
**Contract release:** `1.3.1`  
**Public bridge:** `1.21`

## Compatibility

Contract major version remains `1`. The release is additive: existing provider tokens, including
legacy tokens, request fields, entity lifecycles and response fields remain compatible. Clients
must continue to ignore unknown response fields and approve the new schema hash before writes.

## Runtime controls

- Entity writes are lock-serialized and transactional.
- Payload limit is 65,535 bytes, maximum depth is 8 and maximum node count is 500.
- Mapping targets are checked against their declared company boundary.
- Status exposes only aggregate operational statistics and a `hardening_ready` flag.

## Operational audit

The `local_kbacorp_erp_log` table records provider token, entity type, HMAC hashes of external and
idempotency identifiers, outcome, duration, safe exception class and timestamp. It never stores
payloads, raw external IDs, PII, credentials or exception messages. Retention is configurable from
30 to 3650 days and defaults to 180 days.

## Methodological guarantees

- Course completion does not confirm a competency level.
- Unassessed competency remains `null`, never level 0.
- Completed implementation action or accepted evidence does not raise BMS maturity.
- Maturity changes only through a new confirmed audit using stable criterion codes.
- Learning, implementation and economic correlation is not automatic causality.
