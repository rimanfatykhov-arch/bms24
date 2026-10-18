# KBA Connect v5.0.35.4 — Diagnostics Type Fix

- Fixed Role Sync Diagnostics TypeError.
- `corporate_role_schema_service::read_contact_roles()` is now called with Contact ID, as required by its typed signature.
- Diagnostic output now unwraps the role-read envelope safely.
- Added explicit role-read status, field name, raw Bitrix values, normalized rolecodes and full service result.
- No enrolment or access-delivery behavior changed.
