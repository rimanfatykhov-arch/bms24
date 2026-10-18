# BMS 0.5.17 — Employee CRM UX

Build: 2026091519

## Purpose
Bring the BMS employee registry to a CRM-style record workflow inspired by classic SugarCRM interaction patterns without copying its presentation assets or moving business logic into KBA Theme.

## Delivered
- Employee name opens a dedicated employee record; canonical historical memberships are addressable by membership UUID.
- Compact View / Edit / Delete actions in the employee list.
- Employee record shows company, membership status/source/effective dates, Moodle User ID, phone/email, organizational unit, position, BMS roles, Bitrix Contact ID and synchronization state.
- Employee record links back to existing BMS Organization and Positions sections.
- Edit updates the canonical Moodle user and queues the existing `employee_upsert` reconciliation without creating a second membership.
- Email uniqueness follows the Moodle `allowaccountssameemail` policy; email format and company ACL are still validated server-side.
- Delete means soft closing the active BMS company membership and queues `employee_close`; Moodle User, Bitrix Contact and history are not physically deleted.
- Removal can only mutate state through the dedicated confirmation page; the old direct `employees.php?action=close` mutation path is removed.
- KBA Corporate compatibility-projection rows remain read-only until canonical BMS reconciliation.
- Sync display prioritizes pending/error employee outbox commands over an existing Contact mapping and includes both `employee_upsert` and `employee_close`.
- Consumer diagnostics accept either the KBA Connect bridge service or `\local_bitrixsync\task\process_bms_bridge`, preventing a false missing-consumer warning.

## Architecture
No new Person/entity registry, no duplicate employee profile storage and no new integration path. Moodle User remains the person identity; `local_kbacompany_member` remains the company-membership history; KBA Connect consumes the existing durable outbox. KBA Corporate remains the learning bounded context.

## Schema
Schema-neutral. No new tables, fields, capabilities or external services. Build 2026091519 is a schema-neutral finalization after draft build 2026091518.
