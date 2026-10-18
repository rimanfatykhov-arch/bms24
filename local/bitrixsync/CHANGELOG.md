## 5.0.36.1.23 — BMS Employee Position Projection
- Bitrix Contact standard `POST` is handed to the BMS employee upsert contract as `position`.
- Empty `POST` is passed as empty and never forces a destructive profile clear.
- KBA Connect still owns Bitrix transport only; canonical BMS Position creation/assignment is not performed.
- No schema, enrolment, role or course-flow changes.

## 5.0.36.1.22
- Fixed BMS employee PHONE mapping.
- Guarded against email-like values in Bitrix PHONE.
- Existing Contact phone is reconciled when BMS provides a valid phone.

## 5.0.36.1.21 — BMS Employee Sync Stabilization

- Added Corporate Public API mapping recovery before BMS Company upsert.
- Added list-profile fallback for Company Contact hydration; no more silent contact disappearance.
- Added per-contact resolution diagnostics to BMS Company sync results.
- Persisted Corporate Company mapping after successful employee reconciliation.
- No schema, ACL, cron or enrolment-flow changes.

## 5.0.36.1.20 — Resolved BMS Field Selector Fix

- BMS Company field selector now reads the exact logical Company resolution cache used by `crm_fields.php`.
- All resolved Company fields are selectable and display FIELD_NAME/XML_ID/type.
- Live discovery supplements the selector but is no longer required for cached fields.
- Explicit field resolution uses the verified cache first, then live Bitrix REST.
- No schema, ACL, cron or enrolment changes.

## 5.0.36.1.19 — Cached BMS Field Selector Fix

- BMS client Company field selector now loads verified boolean fields from the local CRM Field Engine cache before attempting live Bitrix discovery.
- Prevents an empty selector when the settings-page REST refresh is unavailable while CRM Fields already reports the field as Ready.
- No schema, ACL, cron or synchronization-contract changes.

# KBA Connect Changelog

## v5.0.36.1.17 — BMS Bridge Diagnostics

- Added read-only Company trace by Bitrix Company ID.
- Added BMS Bridge card in Diagnostics.
- Added queue/BMS/Corporate/contact mapping visibility without changing synchronization behavior.
- Schema-neutral build 2026083627.

## v5.0.33 RC1 — Corporate Dashboard Pairing

- Added one-time cryptographically secure pairing codes with 15-minute TTL and single-use semantics.
- Added `POST /corporate/pair` and `GET /corporate/connection/status`.
- Added revocable per-connection integration tokens stored only as SHA-256 hashes.
- Added pairing IP rate limiting (5 failed attempts per 10-minute window, 10-minute block).
- Upgraded Corporate endpoint Bearer authentication to connection-token lookup with one-release legacy token fallback.
- Added Corporate pairing UI, connection metadata, last-seen tracking and revocation.
- Preserved v5.0.32 role schema endpoints and non-destructive MERGE role synchronization.
- Added pairing diagnostics and `[CORPORATE_PAIRING]` logging without secret disclosure.

# KBA Connect v5.0.14

## Stable Release

Added:
- unified KBA Connect Admin Portal
- controlled execution pipeline
- audit and persistence layer
- Moodle/Corporate/CRM adapter architecture
- verification and reporting system

## Validation
- RC1 completed
- RC2 completed
- Stable package prepared

# KBA Connect v5.0.32 RC1 — Corporate Role Schema Bridge

Added:
- Corporate Dashboard Public API v1.10 role-schema compatibility layer.
- Internal Bearer authentication for Corporate Dashboard → KBA Connect endpoints.
- `GET /local/bitrixsync/corporate/schema/status/`.
- `POST /local/bitrixsync/corporate/schema/provision/`.
- Idempotent Bitrix24 Contact enumeration field provisioning.
- Runtime Bitrix enum ID → stable role-code mapping.
- Bitrix Contact corporate-role reader.
- MERGE-only `roles[]` handoff when Corporate Dashboard exposes `merge_employee_roles`.
- Corporate role-schema diagnostics and `[CORPORATE_SCHEMA]` logging category.

Safety:
- No direct `local_kbacorporate` table access.
- No Bitrix credentials moved to Corporate Dashboard.
- No automatic userfield deletion.
- No destructive role replace.
- Corporate role sync failure does not roll back Moodle user creation or enrolment.


## v5.0.33.2 Pairing Storage Recovery
- Forced new 2026083302 DB migration for pairing tables.
- Admin UI no longer crashes when pairing tables are missing.
- Added cli/repair_pairing_schema.php as emergency fallback.
- No Bitrix24 or Corporate Dashboard business data is deleted.
