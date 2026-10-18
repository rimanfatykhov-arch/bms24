# KBA Corporate Dashboard v0.28.2

## BMS ↔ Competency JSON import/export

- Added company-level JSON import/export for BMS criterion ↔ Moodle competency mappings.
- Stable keys are used: `tool_code`, `criterion_code`, `competency_code` (`competency.idnumber`).
- Supported schema: `kba-bms-competency-links`, version `1.0`.
- Import is two-stage: validation/preview, then explicit apply.
- Preview distinguishes create, update, unchanged and error/missing-reference rows.
- Import is transactional; valid changes are committed together.
- Existing mappings are updated without duplicates.
- Changed BMS tools are automatically marked as draft through the existing versioning mechanism.
- Export produces the same canonical schema for later Moodle ↔ Odoo/BMS Digital ERP exchange.
- Added upgrade savepoints for v0.28.1 and v0.28.2; no schema migration is required.

Build: `2026090289`
