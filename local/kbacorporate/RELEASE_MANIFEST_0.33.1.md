# RELEASE MANIFEST — v0.33.1

- Product: KBA Corporate Dashboard
- Component: `local_kbacorporate`
- Release: `0.33.1 — Управление доказательствами внедрения`
- Build: `2026090811`
- Base release: `0.33.0`
- Status: BETA / prepared for pilot runtime validation
- ZIP root: `kbacorporate/`
- Install artifact: `KBA_Corporate_Dashboard_v0.33.1_Implementation_Evidence_Management_full.zip`
- Checksum artifact: companion `.zip.sha256`

## Schema impact

- Added table `local_kbacorp_impl_evid`.
- Added table `local_kbacorp_impl_evlog`.
- Added filearea `implementation_evidence_record`.
- No existing field is removed or renamed.
- No destructive migration.
- Upgrade savepoint: `2026090811`.

## Primary changed source

- `classes/local/implementation_evidence.php`
- `classes/form/implementation_evidence_submission_form.php`
- `implementation_evidence.php`
- `implementation_task.php`
- `implementation_task_edit.php`
- `classes/form/implementation_task_form.php`
- `classes/local/implementation_task_lifecycle.php`
- `classes/local/health.php`
- `lib.php`
- `classes/local/repository.php`
- `classes/privacy/provider.php`
- `db/install.xml`
- `db/upgrade.php`
- `lang/ru/local_kbacorporate.php`
- `lang/en/local_kbacorporate.php`
- `styles.css`
- `tests/implementation_evidence_test.php`
- `version.php`

## Required release documents

- `RELEASE_NOTES_0.33.1.md`
- `CHANGELOG_0.33.1.md`
- `TEST_REPORT_0.33.1.md`
- `AI_DECISION_LOG_0.33.1.md`
- `RELEASE_MANIFEST_0.33.1.md`

## Module documentation

- `docs/PRODUCT_PASSPORT_v0.33.1.md`
- `docs/SYSTEM_ARCHITECTURE_v0.33.1.md`
- `docs/INSTALLATION_v0.33.1.md`
- `docs/OPERATIONS_v0.33.1.md`
- `docs/API_CONTRACT_v0.33.1.md`
- `docs/DATA_MODEL_v0.33.1.md`
- `docs/SECURITY_AND_PRIVACY_v0.33.1.md`
- `docs/TROUBLESHOOTING_v0.33.1.md`

## Release gate state

Static source and packaging gate completed before handoff. Pilot Moodle runtime validation and PHPUnit execution remain required before Stable classification.
