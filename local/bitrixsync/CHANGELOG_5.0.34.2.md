# KBA Connect v5.0.34.2 — Changelog

- Corporate roles are now a first-class Contact field in the production CRM Field Engine.
- Corporate Role Schema delegates field discovery/creation to `crm_field_manager`.
- Removed the second independent Bitrix24 field-discovery/health path from Corporate Roles.
- Enum role provisioning remains idempotent and non-destructive.
- Pairing is unchanged.
- Contact Application Token remains only for inbound ONCRMCONTACTUPDATE authentication.
