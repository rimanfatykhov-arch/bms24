# RELEASE MANIFEST — v0.33.0

- Product: KBA Corporate Dashboard
- Component: `local_kbacorporate`
- Release: `0.33.0 — Критерий аудита → действие по внедрению`
- Build: `2026090810`
- Base release: `0.32.3`
- Status: BETA / prepared for pilot runtime validation
- ZIP root: `kbacorporate/`
- Install artifact: `KBA_Corporate_Dashboard_v0.33.0_Audit_to_Implementation_Action_full.zip`
- Checksum artifact: companion `.zip.sha256`

## Schema impact

- No new tables.
- No new fields or indexes.
- No destructive migration.
- Existing `local_kbacorp_impl_task` remains the authoritative action store.

## Primary changed source

- `classes/local/implementation_actions.php`
- `classes/local/implementation_audits.php`
- `implementation_actions.php`
- `implementation_audit.php`
- `implementation_plan.php`
- `lang/ru/local_kbacorporate.php`
- `lang/en/local_kbacorporate.php`
- `styles.css`
- `tests/implementation_actions_test.php`
- `db/upgrade.php`
- `db/install.xml`
- `version.php`

## Required release documents

- `RELEASE_NOTES_0.33.0.md`
- `CHANGELOG_0.33.0.md`
- `TEST_REPORT_0.33.0.md`
- `AI_DECISION_LOG_0.33.0.md`
- `RELEASE_MANIFEST_0.33.0.md`

## Release gate state

Static source and packaging gate completed before handoff. Pilot Moodle runtime validation remains required before Stable classification.
