# BMS v0.6.6 — Provider-Neutral Outbox / Integration Contract

Build `2026092127`.

- Added additive durable `local_kbacompany_eventout` for provider-neutral BMS integration events.
- Existing `local_kbacompany_syncout` remains unchanged and continues to serve KBA Connect / Bitrix24 command reconciliation.
- Existing `company_reconcile`, `employee_upsert` and `employee_close` commands now dual-write a sanitized change-notification event when the canonical BMS Company exists.
- Provider-neutral event payloads contain no Bitrix Company/Contact IDs, Moodle user IDs, employee email/phone or KBA Corporate IDs; consumers refresh canonical state through BMS Management Public API v1.
- Event envelope upgraded to schema `1.1` with producer, optional Workspace UUID projection and correlation ID while keeping stable idempotency semantics.
- Added independent event lifecycle `pending → done/error → retry`, at-least-once contract, diagnostics and regression tests.
- KBA Connect behavior, Bitrix REST ownership, Corporate Learning ownership and Deal → Product → Course enrolment semantics remain unchanged.
- Additive schema only; no user-facing UX or capability changes.
