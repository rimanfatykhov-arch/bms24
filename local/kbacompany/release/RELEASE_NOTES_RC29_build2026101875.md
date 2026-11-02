# BMS24 build2026101875 — hotfix battleplan_date (02.11.2026)

## Incident
Opening BMS workspace may throw `battleplan_date` at `bms_workspace.php:145`, from `battle_plan_service::week_start()` after a locale-formatted `userdate()` value is supplied. The stack proves the input is not accepted as ISO `Y-m-d`; the exact unexpected string requires server-side diagnostics.

## Fix
- Add `battle_plan_service::user_today(?int $timestamp = null)`: fixed ASCII Gregorian `Y-m-d` using Moodle user's timezone via `core_date::get_user_timezone_object()`.
- Fix BMS workspace week widget, work queue and overdue comparison; the task subtask-week views; implementation program week views.
- Keep strict `week_start()` validation unchanged; do not swallow errors or rewrite plan records.
- Add Moodle PHPUnit timezone boundary regression tests for 2026-11-02 and preceding Sunday; test that non-ISO dates are still refused.
- Only a Moodle upgrade savepoint; no DDL, row changes or permission changes.

## Installation
Back up first, install the full cumulative `INSTALL_local_kbacompany_1.1.0-rc29_build2026101875.zip` plugin ZIP through Moodle admin and complete the plugin upgrade. Purge caches. Test workspace, tasks, Battle Plans and implementation program weekly pages using a normal employee and manager account. See the standalone hotfix option if urgent.

## Verification limits
Static and isolated tests only, not yet verified on the live edu.kzn.mba server. No claim of end-to-end Moodle acceptance.
