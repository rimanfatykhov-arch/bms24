# Release Manifest v0.34.0

## Identity

- Component: `local_kbacorporate`
- Release: `0.34.0 — Сравнение аудитов «до / после»`
- Build: `2026090901`
- Maturity: `MATURITY_BETA`
- Minimum Moodle: `2022041900` (Moodle 4.0)

## Installer

- `KBA_Corporate_Dashboard_v0.34.0_Audit_Before_After_Comparison_full.zip`
- SHA-256: populated after final packaging in the companion `.sha256` file
- ZIP root: `kbacorporate/`

## Release documentation

- `RELEASE_NOTES_0.34.0.md`
- `CHANGELOG_0.34.0.md`
- `TEST_REPORT_0.34.0.md`
- `AI_DECISION_LOG_0.34.0.md`
- `RELEASE_MANIFEST_0.34.0.md`

## Module documentation

- `docs/PRODUCT_PASSPORT_v0.34.0.md`
- `docs/SYSTEM_ARCHITECTURE_v0.34.0.md`
- `docs/INSTALLATION_v0.34.0.md`
- `docs/OPERATIONS_v0.34.0.md`
- `docs/API_CONTRACT_v0.34.0.md`
- `docs/DATA_MODEL_v0.34.0.md`
- `docs/SECURITY_AND_PRIVACY_v0.34.0.md`
- `docs/TROUBLESHOOTING_v0.34.0.md`

## Database impact

No schema changes. Upgrade savepoint `2026090901` only.

## Rollback

Restore `v0.33.4` plugin files and purge Moodle caches. Existing database records remain unchanged.
