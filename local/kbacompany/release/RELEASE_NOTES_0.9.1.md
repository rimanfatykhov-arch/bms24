# local_kbacompany 0.9.1 — Odoo Adapter Foundation

Build: `2026092600`

- Added a read-only, restricted, disabled-by-default `BMS Odoo Adapter API v1`.
- Added exact Company + Odoo environment readiness over existing human-confirmed `extmap` identities.
- Added a sync manifest with hashes/counts for BMS Public API v1 Company, Positions, Indicators and approved Budgets snapshots.
- Added paged active Odoo mapping export for Company / Position / Indicator.
- No Odoo credentials, endpoint secrets or direct Odoo DB access are stored in Moodle.
- No remote Odoo HTTP request is made by Moodle in this slice.
- No operational actual is imported and no Indicator fact is written automatically.
- Existing Bitrix/KBA Connect transport, provider-neutral outbox, Public API DTOs, DB schema, ACL capabilities, Theme and AI plugin remain unchanged.
