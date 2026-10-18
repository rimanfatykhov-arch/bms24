# KBA Connect v5.0.34.4 — Enum Provisioning Fix

- Existing enum values are preserved by ID.
- Missing role values are appended without ID.
- Only documented enum fields are sent back to Bitrix24.
- No existing enum item is deleted.
- Provisioning is verified by re-reading the field.
- Adds administrator-facing enum provisioning diagnostics.
