# v0.30.2 — BMS Tool JSON Import

- Added `Импорт инструментов JSON` to Framework Manager.
- Supports `kba-bms-tools` v1.0/v1.1.
- Dry Run / Preview / Apply workflow.
- Stable-code upsert for tools and criteria.
- Imports criterion methodology fields: description, min/max level descriptions, evidence required, target score, weight, sort order, mandatory, active.
- No destructive deletion: criteria absent from JSON are untouched.
- Changed tools are marked draft and remain subject to the existing publish/version workflow.
- Tools JSON export now includes the methodology fields needed for round-trip export/import.
