# RC25 build2026101354 — Workspace task parity fix

Issue: the company Tasks screen showed an assigned, overdue BMS task while Workspace showed zero. Dashboard previously consumed only the aggregator, which silently catches individual provider failures.

Fix: Home page reads canonical BMS `task_service::list_page(scope=my)` (the same access boundary and filters as Tasks.php), taking open/overdue counters from its SQL counts and the first 25 records for presentation; merges open external-provider records only and excludes duplicated BMS records, completed and cancelled. No new data tables or permission changes.

Acceptance: install and verify task parity, including overdue, done, and cross-company negative tests; live Moodle NOT RUN.
