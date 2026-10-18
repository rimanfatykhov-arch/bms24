# KBA Connect v5.0.36.1.17 — BMS Bridge Diagnostics

Hotfix diagnostics release on top of v5.0.36.1.16.

## Delivered
- Adds `/local/bitrixsync/bms_diagnostics.php`.
- Adds a **BMS Bridge** card to the Diagnostics hub and Extended Diagnostics page.
- Adds read-only inspection by Bitrix Company ID.
- Shows live Bitrix Company title and effective `KBA — Клиент BMS` field value.
- Shows the latest `company / bms_sync` queue item, attempts, error and stored result payload.
- Shows active Bitrix Company → BMS Company mapping and BMS Company UUID when available.
- Shows existing Bitrix Company → KBA Corporate mapping from KBA Connect's own bridge table.
- Shows BMS outbox counters through `local_kbacompany` integration API when the viewer has access.
- Shows Bitrix Contacts for the Company with Moodle User / Corporate employee mapping where available.
- Adds an explicit read-only diagnostic conclusion that identifies the first observable break in the Company chain.

## Safety
- No CRM provisioning or Bitrix write calls.
- No database writes in the new diagnostic service/page.
- No direct reads/writes to `local_kbacorp_*` tables.
- No schema changes, access changes, scheduled-task changes or enrolment changes.
- Existing Deal → Product → Course → Enrolment behavior is unchanged.
