# Changelog v0.33.4

## Added

- Company economic-indicator registry.
- Stable system catalogue and company-specific custom indicators.
- Monthly, quarterly, yearly and custom reporting periods.
- Actual, plan and forecast value scenarios.
- Manual, ERP, import and API source classification.
- Optional same-company BMS audit linkage.
- Immutable indicator/value change log.
- Filtered CSV export with formula-injection protection.
- Company-scoped permissions and navigation entry.
- Idempotent internal upsert contract based on stable indicator codes.
- Regression tests for catalogue, values, scenarios, uniqueness and legacy migration.

## Changed

- Existing audit economic snapshots are backfilled into the new registry during upgrade.
- New edits of the legacy audit snapshot synchronise mapped actual values into the registry.
- Economic values displayed in the registry now support filtered export and scenario separation.
- Privacy metadata/export/anonymisation covers the new tables, including scenario data and verifier IDs embedded in history payloads.

## Database

Added:

- `local_kbacorp_econ_ind`;
- `local_kbacorp_econ_val`;
- `local_kbacorp_econ_log`.

The migration is additive and does not delete existing audit snapshots.
