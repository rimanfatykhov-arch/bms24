# BMS v0.6.5 — BMS Public API Foundation

Build `2026092126`.

- Added a stable read-only `BMS Management Public API v1` boundary for future BMS Cloud and Odoo consumers.
- Added provider-neutral snapshot resources for Company, Employees, Organization, Positions, Indicators and effective approved Budgets.
- Public DTOs use Company UUID as the management security boundary and expose Workspace UUID only as a BMS Cloud-owned projection.
- Public API responses use a versioned envelope with `api_version`, `schema_version`, `resource`, `company_uuid`, `workspace_uuid`, `generated_at`, `snapshot_hash` and `data`.
- Employee email/phone are opt-in and require BMS organization-management permission; provider-specific Bitrix IDs and legacy storage IDs are not exported.
- Budget export contains approved management plan/limits only. It deliberately excludes operational actual ledger ownership and transaction execution.
- Added a dedicated Moodle external service `local_kbacompany_public_v1`, disabled by default and restricted to explicitly authorised service users.
- No business write API is introduced in v0.6.5. Existing BMS UI, KBA Connect and KBA Corporate behavior remain unchanged.
- No database schema or capability changes.
