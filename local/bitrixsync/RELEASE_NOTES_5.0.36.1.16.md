# KBA Connect v5.0.36.1.16 — BMS Company & Employee Bridge

Companion release for local_kbacompany v0.5.16.

## Delivered
- Adds Bitrix Company field definition `KBA — Клиент BMS` (`KBA_CONNECT_BMS_CLIENT`, boolean).
- Adds durable BMS outbox consumer scheduled every minute.
- `employee_upsert`: BMS User -> resolve/create Bitrix Contact -> ensure COMPANY_ID -> KBA Corporate employee membership -> acknowledge BMS command.
- `employee_close`: closes KBA Corporate employee membership without deleting the Bitrix Contact or Moodle history.
- `company_reconcile`: imports/updates a BMS Company only when the explicit Bitrix flag is true and reconciles its Contacts.
- Accepts authenticated `ONCRMCOMPANYADD` / `ONCRMCOMPANYUPDATE` through the existing v5 webhook when explicitly enabled.
- Contact profile updates additionally refresh BMS employee identity, but never trigger course enrolment.
- Existing `Deal -> Product -> Course -> Enrolment` behavior is unchanged.

## Safety
- No direct writes to `local_kbacorp_*` tables.
- No Bitrix credentials are exposed to local_kbacompany.
- Existing Bitrix/Corporate company mappings are reused so legacy BMS company UUIDs can be adopted.
- Feature defaults OFF after upgrade; enable after BMS v0.5.16 is installed and CRM field provisioning is verified.
