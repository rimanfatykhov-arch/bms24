# KBA Connect v5.0.34.6 — Changelog

- Fixed fatal error on Corporate page: `role_schema_diagnostic() on null`.
- CorporateRoleSchemaService is now initialized once at page startup.
- Status, provisioning and diagnostics reuse the same service instance.
- No Bitrix24, pairing or CRM business logic changed.
