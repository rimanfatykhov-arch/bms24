# KBA Corporate Dashboard 0.17.2.1 — Business-role Query Hotfix

Release date: 2026-09-01  
Build: `2026090104`  
Public API: `1.15` (unchanged)

This hotfix corrects the active business-role lookup used by **My competencies**. Moodle 4.0 does not allow the same named SQL placeholder to be reused twice. The query now passes independent `effectivefromnow` and `effectivetonow` values.

The defect produced: `Wrong number of query parameters. Expected 4, got 3` when opening an employee competency profile containing an active business-role assignment.

Install the full ZIP over v0.17.2. Do not uninstall the plugin. Build `2026090104` adds a non-destructive upgrade savepoint and makes no database schema changes.
