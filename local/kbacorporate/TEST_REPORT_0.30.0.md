# TEST REPORT — v0.30.0

## Static release gate
- ZIP root: `kbacorporate/` only — planned/verified during packaging.
- Moodle component: `local_kbacorporate`.
- Numeric build: `2026090600` (> 2026090308).
- Database schema changes: none.
- PHP syntax check: required before packaging.
- ZIP integrity: required before delivery.
- SHA-256: generated with release artifact.

## Functional scenarios implemented
1. Legacy `kba-bms-competency-links` v1.0 → Preview → Apply.
2. Canonical `kba-bms-mapping-library` v1.0/v1.1 → normalization → Preview → Apply.
3. Duplicate link in one upload → validation error.
4. Export → canonical `kba-bms-mapping-library` v1.1.
5. Company tool lookup preferred over global read-only tool.

## Manual regression still required in production Moodle
- Upload the supplied v1.1 mapping library using Dry Run.
- Confirm 137 rows are recognized and resolve to existing criteria/competencies.
- Apply only after reviewing missing/error rows.
- Confirm changed BMS tool is marked for draft/version handling.
