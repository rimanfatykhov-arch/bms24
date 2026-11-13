# BMS Calendar Notification Engine — 1.1.0-alpha12

The notification engine is a scheduled projection, not a scheduling source of truth.

## Idempotency

Every signal is reduced to SHA-256 identity material (Company, recipient, signal kind and canonical source/date identity). `communication_service::ensure_system_notification()` converts that identity into a deterministic RFC-4122-shaped UUID. The existing unique `local_kbacompany_commnotif.uuid` index therefore acts as the durable replay guard.

No hidden reminder table is added.

## Delivery semantics

The durable BMS Communication Center claim happens first. Moodle messaging is best-effort and at-most-once per durable claim. If transport fails, the BMS notification remains available and cron will not emit duplicate popups.

## Boundaries

The engine does not read colleagues’ personal Moodle calendars and does not alter Tasks, Meetings, Battle Plans, Management Cycles or Moodle Calendar events.
