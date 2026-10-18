# Release Manifest v0.33.4

## Identity

- Component: `local_kbacorporate`
- Release: `0.33.4 — Реестр экономических показателей`
- Build: `2026090815`
- Maturity: `MATURITY_BETA`
- Minimum Moodle: `2022041900` (Moodle 4.0)

## Installer

- `KBA_Corporate_Dashboard_v0.33.4_Economic_Indicators_Registry_full.zip`
- SHA-256: populated after final packaging in the companion `.sha256` file
- ZIP root: `kbacorporate/`

## Release documentation

- `RELEASE_NOTES_0.33.4.md`
- `CHANGELOG_0.33.4.md`
- `TEST_REPORT_0.33.4.md`
- `AI_DECISION_LOG_0.33.4.md`
- `RELEASE_MANIFEST_0.33.4.md`

## Module documentation

- `docs/PRODUCT_PASSPORT_v0.33.4.md`
- `docs/SYSTEM_ARCHITECTURE_v0.33.4.md`
- `docs/INSTALLATION_v0.33.4.md`
- `docs/OPERATIONS_v0.33.4.md`
- `docs/API_CONTRACT_v0.33.4.md`
- `docs/DATA_MODEL_v0.33.4.md`
- `docs/SECURITY_AND_PRIVACY_v0.33.4.md`
- `docs/TROUBLESHOOTING_v0.33.4.md`

## Database impact

Additive creation of:

- `local_kbacorp_econ_ind`;
- `local_kbacorp_econ_val`;
- `local_kbacorp_econ_log`.

No existing table or column is removed.

## Rollback prerequisite

Full rollback requires a pre-upgrade database backup. Replacing plugin files alone does not remove the new tables.
