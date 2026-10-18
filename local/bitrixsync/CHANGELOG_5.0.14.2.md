# KBA Connect v5.0.14.2 — Changelog

## Production Launch consolidation

- Wired controlled UI/CLI launch to the existing real `deal_flow_service`.
- Restored the business rule for allowed Bitrix deal stages on automatic traffic.
- Added `coursemap` fallback to the real production course resolver.
- Added Production Preflight and explicit REAL execution gate.
- Replaced fake History/Timeline data with `local_bitrixsync_dealrun` data.
- Added launch settings and schema checker.
- Added production launch, test and rollback documentation.
