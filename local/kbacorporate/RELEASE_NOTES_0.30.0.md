# KBA Corporate Dashboard v0.30.0 — BMS Mapping Engine

## Purpose
Make the Moodle BMS mapping importer use the canonical KBA BMS JSON library directly.

## Changes
- `bms_competency_io.php` accepts both JSON contracts:
  - `kba-bms-mapping-library` v1.0/v1.1 with `mappings[]`;
  - legacy `kba-bms-competency-links` v1.0 with `links[]`.
- Both formats are normalized to one internal import model before Preview / Apply.
- Duplicate stable-code links inside one upload are rejected in Dry Run.
- Company-owned BMS tools are preferred over global tools with the same code.
- Export now uses canonical `kba-bms-mapping-library` v1.1.
- Existing transaction-based Apply behavior is preserved.
- Existing changed-tool draft/version workflow remains in place through `implementation_audits::save_criterion_competency()`.

## Data migration
No database schema change.

## Compatibility
Moodle 4.0+; upgrade from the current 0.29.6.4 build is supported.

## Related data artifact
`KBA_BMS_MAPPING_LIBRARY_v1.1_DRAFT_137.json` contains one initial primary competency mapping for every one of the 137 Point A criteria. It is intentionally marked `draft-methodology` and should be reviewed via Dry Run before Apply.
