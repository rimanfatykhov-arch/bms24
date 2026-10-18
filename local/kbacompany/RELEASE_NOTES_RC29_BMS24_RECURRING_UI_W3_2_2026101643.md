# BMS24 RC29 / build2026101643 - UI Architecture W3.2: Recurring Series Owned Presentation

**Base:** build2026101642. **Status:** staging/test Moodle release candidate, not FINAL. **Date:** 18.10.2026.

## Scope

- Migrates `recurring_tasks.php` presentation to the plugin-owned BMS24 UI Kit when `local_kbacompany/bms24_shell_mode=owned`.
- Keeps `legacy` mode as the unchanged fallback path.
- Uses existing BMS24 owned fragments and UI Kit classes for the recurring registry/header/actions/filter/status/table/panels.
- Extends typed UI Kit status variants for recurring series states: `draft`, `active`, `archived` (`paused` already existed).
- Adds scoped recurring CSS under `.bms24-app .bms24-recurring-center`.

## Preserved Contracts

- No database schema changes.
- No ACL/capability changes.
- No scheduler changes.
- No recurrence idempotency changes.
- No company-scope changes.
- Existing GET/POST parameters, `sesskey`, `revision`, `series`, `dutypositionuuid`, status filter, form IDs and JS endpoints are preserved.
- Recurring generator, `taskrec` revisions, occurrence journal and Battle Plan links are not modified.

## Changed Files

- `recurring_tasks.php`
- `classes/local/bms24_ui_kit_service.php`
- `bms_ui/ui_kit.css`
- `bms_ui/task_center.css`
- `tests/bms24_ui_kit_service_test.php`
- `db/upgrade.php` no-DDL savepoint only
- `version.php`

## Local QA

- `php -l`: all plugin PHP files pass via `scripts/qa_local.py`.
- RU/EN language parity: 8085/8085 keys.
- XML syntactic parse: 1 DB XML file.
- `git diff --check`: pass.
- ZIP CRC and SHA256 are produced in the release package.

## Not Run

- Moodle PHPUnit/Behat.
- Live Moodle install/upgrade.
- MySQL/MariaDB upgrade verification.
- Cron/concurrency recurrence tests.
- Browser acceptance in KBA Theme and Boost.
- Cross-company manual security matrix.

## Installation Notes for Test Moodle

1. Backup code, database and moodledata.
2. Install only `INSTALL_ME_local_kbacompany_v1.1.0-rc29_build2026101643.zip`; the archive root is `kbacompany/`.
3. Run standard Moodle upgrade/Notifications.
4. Purge Moodle caches.
5. Start with `local_kbacompany/bms24_shell_mode=legacy`, verify rollback baseline, then switch to `owned` for W3.2 acceptance.

## Rollback

Set `local_kbacompany/bms24_shell_mode=legacy` and purge caches. No data migration is introduced by this build. For code rollback, restore the previous tested plugin directory and database backup according to the site's standard rollback procedure.
