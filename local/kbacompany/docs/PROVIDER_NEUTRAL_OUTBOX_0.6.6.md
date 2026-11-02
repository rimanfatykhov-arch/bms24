# Provider-Neutral Integration Outbox — v0.6.6

## Purpose

BMS Management must emit stable provider-neutral change notifications without making the current Bitrix24 integration contract a permanent platform API.

## Two outboxes during transition

`local_kbacompany_syncout` remains the legacy command outbox consumed by KBA Connect. Its command kinds and payload contract are not changed in v0.6.6.

`local_kbacompany_eventout` is the new provider-neutral event outbox intended for the future BMS Integration Hub. The two lifecycles are independent.

## Event envelope 1.1

Required fields:

- `schema_version`
- `event_id`
- `event_type`
- `producer`
- `company_uuid`
- `entity_type`
- `entity_uuid`
- `revision`
- `occurred_at`
- `idempotency_key`
- `correlation_id`
- `payload`

`workspace_uuid` is included when a BMS Cloud mapping exists and is otherwise empty.

## Legacy command bridge

Current legacy commands are mirrored as change notifications:

- `company_reconcile` → `integration.company_reconciliation.requested`
- `employee_upsert` → `people.employee_projection.changed` (`change=upsert`)
- `employee_close` → `people.employee_projection.changed` (`change=close`)

The neutral event does not copy legacy provider payload fields. A future Integration Hub receives the event and refreshes the authorized Company/Employees snapshot through BMS Management Public API v1.

## Privacy / ownership boundary

The neutral event outbox intentionally stores no employee contact data and no Bitrix IDs. Bitrix REST remains owned by `local_bitrixsync`; learning execution remains owned by Moodle/KBA Corporate; ERP actual remains external.

## Delivery semantics

- durable DB outbox;
- at-least-once;
- stable idempotency key;
- consumer must be idempotent;
- retry does not rewrite the event envelope;
- completion of the legacy KBA Connect command does not implicitly complete the neutral event, and vice versa.

## Not included in v0.6.6

- no external Integration Hub transport endpoint yet;
- no Odoo adapter;
- no provider fan-out inside Moodle;
- no Kafka/RabbitMQ requirement;
- no changes to current KBA Connect consumer.
