# RELEASE MANIFEST — v0.32.3

- Product: KBA Corporate Dashboard
- Component: `local_kbacorporate`
- Release: `0.32.3 — Индивидуальный план развития сотрудника`
- Build: `2026090809`
- Base release: `0.32.2.1`
- Status: BETA / prepared for pilot runtime validation
- ZIP root: `kbacorporate/`
- Install artifact: `KBA_Corporate_Dashboard_v0.32.3_Individual_Development_Plan_full.zip`
- Checksum artifact: companion `.zip.sha256`

## Schema impact

- Adds 3 fields to `local_kbacorp_devplan`.
- Adds 15 fields and 2 indexes to `local_kbacorp_devitem`.
- Creates `local_kbacorp_devlog`.
- No destructive migration.

## Primary changed source

- `classes/local/development_plans.php`
- `classes/local/development_notifier.php`
- `classes/local/study_plan_progress.php`
- `classes/local/health.php`
- `classes/local/repository.php`
- `classes/privacy/provider.php`
- `db/install.xml`
- `db/upgrade.php`
- `development_plan.php`
- `development_item.php`
- `development_plan_history.php`
- `study_plan_progress.php`
- `lang/ru/local_kbacorporate.php`
- `lang/en/local_kbacorporate.php`
- `styles.css`
- `tests/individual_development_plan_test.php`
- `version.php`

## Required release documents

- `RELEASE_NOTES_0.32.3.md`
- `CHANGELOG_0.32.3.md`
- `TEST_REPORT_0.32.3.md`
- `AI_DECISION_LOG_0.32.3.md`
- `RELEASE_MANIFEST_0.32.3.md`

## Release gate state

Static gate is completed before packaging. Pilot Moodle runtime gate remains required before Stable classification. The final ZIP SHA-256 is stored in the companion checksum file to avoid a circular self-hash inside the ZIP.
