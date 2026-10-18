# BMS Bridge Diagnostics — v5.0.36.1.17

## Purpose
Provide a production-safe read-only trace for one Bitrix24 Company across the BMS bridge.

## Entry point
`/local/bitrixsync/bms_diagnostics.php`

The page is also linked from the KBA Connect Diagnostics hub.

## Trace by Bitrix Company ID
For one Company ID the page reads:

1. Bitrix24 Company card via `crm.company.get`.
2. The live Company CRM userfield list and resolves `KBA_CONNECT_BMS_CLIENT` / compatible legacy label.
3. The effective `KBA — Клиент BMS` value on the Company.
4. KBA Connect `local_bitrixsync_syncqueue` items for `entity_type=company`, `action=bms_sync`.
5. The stored queue result/error from the latest execution.
6. Active BMS external mapping through `local_kbacompany\local\integration_service`.
7. BMS Company diagnostics/outbox summary through the same internal BMS API.
8. Existing KBA Connect Company bridge row to KBA Corporate.
9. Bitrix Contacts belonging to the Company and existing Moodle/Corporate mapping projection.

## Diagnostic conclusions
The page reports the first observable state:
- Bitrix Company cannot be read.
- BMS client field cannot be resolved.
- Company is not a BMS client.
- No Company `bms_sync` queue item has been observed.
- Latest queue item failed.
- Queue item is still pending/processing.
- Queue completed but no active BMS mapping exists.
- Active Bitrix Company → BMS Company mapping exists.

## Read-only guarantee
The diagnostics path does not:
- call CRM add/update/delete;
- provision fields;
- call `set_config`;
- write to KBA Connect queue or bridge tables;
- write BMS/Corporate business data;
- trigger course enrolment.
