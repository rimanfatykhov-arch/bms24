# BMS24 0.9.1 — Odoo Adapter Foundation

Build: `2026092600`

## Boundary

This release implements the Moodle/BMS side of the first Odoo adapter slice. It does **not** put Odoo credentials into Moodle, make direct Odoo database connections, or ingest operational actuals.

The new restricted and disabled-by-default service `local_kbacompany_odoo_v1` exposes the adapter functions and the canonical Public API resource functions required by the manifest:

- adapter contract;
- readiness for an exact BMS Company + Odoo environment;
- a sync manifest containing hashes/counts of canonical Public API v1 snapshots;
- paged active Odoo identity mappings for `company`, `position`, and `indicator`.

The adapter requires an active human-confirmed `res.company` mapping from the existing `local_kbacompany_extmap`. External IDs remain mappings and never replace BMS UUIDs.

## Odoo transport contract

For Odoo 19+ the preferred future external transport is JSON-2. Official reference: https://www.odoo.com/documentation/19.0/developer/reference/external_api.html . The transport implementation remains outside this Moodle slice. XML-RPC/JSON-RPC are not used by this release.

## Ownership

BMS Management remains the owner of target management definitions. Odoo remains the owner of operational transactions and actuals. Missing actual data is not treated as zero.

## Security

All adapter reads are company-scoped before data retrieval. Access requires the existing company context and organization-management permission. The external Moodle service is restricted-users-only and disabled by default.

## Next adapter slice

A later slice may add verified remote connection state and explicit idempotent ingestion of selected operational actuals only after the provider write/provenance contract is approved. No automatic fact writes are introduced here.
