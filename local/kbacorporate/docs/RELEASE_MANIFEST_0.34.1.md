# Release Manifest v0.34.1

## Identity

- Component: `local_kbacorporate`
- Release: `0.34.1 — Панель экономических показателей аудита`
- Build: `2026090904`
- Maturity: `MATURITY_BETA`
- Minimum Moodle: `2022041900` (Moodle 4.0)

## Installer

- `KBA_Corporate_Dashboard_v0.34.1_Economic_Indicator_Dashboard_full.zip`
- SHA-256: populated after final packaging in the companion `.sha256` file
- ZIP root: `kbacorporate/`

## Release documentation

- `RELEASE_NOTES_0.34.1.md`
- `CHANGELOG_0.34.1.md`
- `TEST_REPORT_0.34.1.md`
- `AI_DECISION_LOG_0.34.1.md`
- `RELEASE_MANIFEST_0.34.1.md`

## Module documentation

- `docs/PRODUCT_PASSPORT_v0.34.1.md`
- `docs/SYSTEM_ARCHITECTURE_v0.34.1.md`
- `docs/INSTALLATION_v0.34.1.md`
- `docs/OPERATIONS_v0.34.1.md`
- `docs/API_CONTRACT_v0.34.1.md`
- `docs/DATA_MODEL_v0.34.1.md`
- `docs/SECURITY_AND_PRIVACY_v0.34.1.md`
- `docs/TROUBLESHOOTING_v0.34.1.md`

## Database impact

No schema changes. Upgrade savepoint `2026090904` only.

## Rollback

Restore `v0.34.0.2` plugin files and purge Moodle caches. Existing economic values and audit records remain unchanged.
