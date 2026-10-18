# KBA Connect v5.0.32 RC1 — Release Notes

## Scope
Corporate Role Schema Bridge between KBA Corporate Dashboard Public API v1.10 and Bitrix24 CRM Contact userfields.

## Endpoints
- `GET /local/bitrixsync/corporate/schema/status/`
- `POST /local/bitrixsync/corporate/schema/provision/`

Both endpoints require `Authorization: Bearer <internal-token>` configured as `local_bitrixsync/corporate_integration_token`.
The token is an internal KBA integration token and is not a Bitrix24 credential.

## Bitrix24 field
Entity: CRM Contact.
Label: `KBA — Роли корпоративного обучения`.
Type: enumeration.
Multiple: yes.

The role list is obtained at runtime from:
`\local_kbacorporate\local\bridge::get_bitrix_role_schema()`.

## Provision safety
- Missing field: create.
- Correct field: no change.
- Missing enum value: append without deleting existing values.
- Incompatible type/multiple: structured `field_schema_conflict` error.
- Field deletion is never performed.

## Role reading
Bitrix enumeration IDs are resolved to stable role codes through the actual field schema and cached as `enum_id → rolecode`.

## Corporate role sync
The prepared handoff is `userid + companyid + roles[]`, MERGE-only.
If Corporate Dashboard does not expose the merge method, role sync is skipped without affecting ordinary Moodle enrolment.
