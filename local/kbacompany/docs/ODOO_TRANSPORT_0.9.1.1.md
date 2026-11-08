# BMS24 Odoo JSON-2 Transport Probe — 0.9.1.1

This slice adds a real, read-only HTTP transport probe for Odoo 19+ JSON-2 without enabling operational actual ingestion.

## Runtime contract

- CLI only: `local/kbacompany/cli/odoo_transport_probe.php`.
- `BMS_ODOO_BASE_URL` and `BMS_ODOO_API_KEY` are process environment values and are never persisted by this code.
- `BMS_ODOO_DATABASE` is optional and maps to `X-Odoo-Database`.
- HTTPS is mandatory; URL credentials, query and fragment are rejected.
- The probe calls only `res.users/context_get` and `res.company/read` for the already human-confirmed company mapping.
- The probe performs zero remote writes and zero BMS actual writes.

## Not included

- no cron/background sync;
- no UI secret entry;
- no API-key storage;
- no Position/Indicator actual reads;
- no automatic indicator fact update;
- no Odoo write/create/update/unlink;
- no direct PostgreSQL access.

A later actual-ingestion slice requires an explicit provenance/idempotency/human-confirmation contract.
