# BMS24 1.1.0-rc26 — FINAL readiness candidate (2026101356)

Baseline: RC25/build2026101355. No new features or DB schema migration.

## Fix
- Dashboard obtains a filtered page of open BMS tasks so >25 completed tasks cannot displace current open tasks.
- Full counts remain from canonical unfiltered Task Center query, preserving prior scopes.
- External provider data is still included and deduplicated as before.

## Regression coverage
- Existing open/overdue parity test retained.
- Added test for 28 completed history records with one open overdue task.
- Added test for two company contexts and no cross-company task UUID leakage.

## Acceptance gates
- Static PHP/JS/lang/XML and ZIP: report separately.
- PHPUnit, Behat, live Moodle, MySQL 5.7 load/EXPLAIN, cross-company ACL, backup/rollback: NOT RUN until executed in staging.
- 1.1.0 FINAL NOT READY.
