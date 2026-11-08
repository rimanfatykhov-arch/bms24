# Incident 2026-11-02: BMS workspace crashes with `battleplan_date`

Observed by user on `edu.kzn.mba`, stack:

- `bms_workspace.php` line 145 -> `battle_plan_service::week_start(userdate(time(), '%Y-%m-%d'))`
- `battle_plan_service.php` line 84 -> strict ISO date validation rejects supplied string.

Cause is proven at the interface boundary: formatting for presentation was passed into ISO business-date validation. The exact emitted string and reason the server's `userdate()` value was noncanonical have not been captured. Do not infer a database defect.

## Fix (build2026101875)

Replace locale-facing formatting with `battle_plan_service::user_today()` that obtains current user timezone through Moodle `core_date::get_user_timezone_object()` and always emits Gregorian ASCII `Y-m-d` from `DateTimeImmutable`. Apply at BMS workspace plan widget, action date and task deadline comparisons; also task subtask rollup and implementation project week navigation. Retain `week_start()` strict validation.

No changes to plan rows, ACL, core Moodle, database schema or data. Version savepoint only.

## Verify

1. Back up database and the plugin directory. Install the cumulative zip via Moodle upgrade and purge caches (including OPcache if deployed).
2. Test `bms_workspace.php?uuid=<company UUID>` as employee and manager with Battle Plan widget enabled. It must render instead of `battleplan_date`.
3. Open `tasks.php` detail/subtask weekly panel and project stages week navigation.
4. Verify the current week is 2026-11-02 through 2026-11-08 for the user's timezone on Nov 2; for users still on Nov 1, last week is expected until local midnight.
5. Test that manually entered invalid week values are still rejected and that changing week does not modify plan records.
6. If error persists, record exact `user_today()` output, the PHP timezone, Moodle user timezone, current deployed version, and stack — without publishing credentials or private company records.

## Recovery

If production is blocked, restore the backed-up `local/kbacompany` directory and Moodle database if the upgrade was applied. This release has no schema change. Avoid changing server system clock to hide the problem.

## Scope

A hotfix of the observed fatal route, not proof of full Moodle or client acceptance. Full PHPUnit and server validation must still be done.
