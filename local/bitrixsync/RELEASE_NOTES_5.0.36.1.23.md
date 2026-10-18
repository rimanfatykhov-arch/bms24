# KBA Connect v5.0.36.1.23 — BMS Employee Position Projection

Build: `2026083633`

## Fixed
- `crm.contact.POST` is forwarded to BMS employee upsert as `position`.
- Works for Contact webhook and Company contact reconciliation paths because both use `sync_contact_to_bms()`.
- Empty `POST` is forwarded as empty and does not request destructive clearing.

## Companion
BMS `local_kbacompany` `0.6.9.2+` consumes the field and updates the Moodle custom profile projection.

## Unchanged
No DB schema, ACL, course enrolment, role merge or CRM ownership changes.
