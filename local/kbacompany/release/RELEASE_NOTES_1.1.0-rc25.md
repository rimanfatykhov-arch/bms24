# BMS24 1.1.0-rc25 / build 2026101352 — Workspace Simplification

Baseline: 1.1.0-rc24 / build 2026101351.

Scope: usability-only optimisation of the main BMS24 Workspace screen. No new domain functionality, no database schema changes, no capability changes.

What changed:
- Rebuilt the main Workspace composition to reduce visual noise and duplicate signals.
- Grouped the daily counters into one compact "What matters now" overview with direct links to Inbox, Tasks, Review and Learning.
- Kept "Today" at the top, then placed the most useful action blocks first: overview, attention, my tasks and management execution.
- Removed the separate four-card management pulse row because the same information was already repeated in the weekly execution and attention blocks.
- Moved reference blocks (business architecture and company context) lower on the page and made the company card title clearer.

Static validation:
- PHP syntax for changed files PASS.
- Full plugin PHP lint PASS.
- No JS, DB or API contract changes.

Release status: RC25 candidate for visual/user acceptance. 1.1.0 FINAL remains NOT READY until live Moodle acceptance is completed.

## Hotfix build 2026101353 — Moodle upgrade cannotdowngrade
Removed the duplicated `upgrade_plugin_savepoint(true, 2026101352, 'local', 'kbacompany')` from `db/upgrade.php` found in the prior INSTALL_ME 2026101352. New build 2026101353 has a unique no-op savepoint so it upgrades cleanly whether the database already recorded 2026101352 or remains on 2026101351. No schema or data changes. Moodle live verification NOT RUN. Do not manually downgrade plugin version in DB.

### build2026101355 — Task/Workspace regression guard
- Added PHPUnit scenario in tests/task_service_test.php asserting canonical open/overdue counts and task visibility on Workspace match Task Center for a real assigned overdue task.
- No business logic or schema changes. PHPUnit NOT RUN; needs Moodle test DB.
