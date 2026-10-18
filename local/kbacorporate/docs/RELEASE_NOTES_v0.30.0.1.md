# KBA Corporate Dashboard v0.30.0.1

## BMS Mapping Engine Preview Hotfix

- Dry Run of canonical `kba-bms-mapping-library` no longer aborts the entire preview on one problematic database row.
- Stable-code lookups use deterministic multi-record resolution for historical duplicate BMS tool/criterion/mapping rows.
- Moodle competency lookup requests only fields required by the importer (`id`, `idnumber`) for Moodle 4.0 compatibility.
- Per-row validation errors are displayed in the preview instead of a generic page-level `invalid_parameter_exception`.
- No database schema changes.
