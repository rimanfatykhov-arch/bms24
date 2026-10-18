# local_kbacompany 1.1.0-alpha6 — Unified Management Calendar

Build: `2026100702`
Baseline: `1.1.0-alpha5 build2026100701`
Baseline INSTALL SHA256: `7768ccdf84d6649360eeb10c8506493cf15865b5c48913f1572616a3dc598bd1`

## Purpose

Turn the BMS24 calendar into a unified management calendar without introducing a second calendar database or moving ownership away from canonical domains.

## Added

- Moodle Calendar events remain available and retain controlled manual-event CRUD.
- Canonical BMS Tasks are projected by due date (or start date when no due date exists).
- Participant-visible Meetings are projected by scheduled start/end.
- Permission-visible Battle Plans are projected by period start.
- Management Cycle checkpoints are projected by planned time for users allowed to manage management cycles.
- Source filter: All / Moodle / Tasks / Meetings / Battle Plan / Checkpoints.
- Separate visual treatment and KPI counts for Moodle and BMS sources.
- Direct links open the canonical BMS record; calendar never edits Tasks, Meetings, Battle Plans or checkpoints.

## Security and architecture

- Company UUID remains the BMS security boundary.
- Task projection reuses Task Center SQL visibility rules.
- Meeting projection requires active participation in SQL.
- Battle Plan projection reuses Battle Plan access SQL.
- Management checkpoints remain manager-only.
- No fetch-all then PHP filter for Company data.
- No BMS event-copy table.
- `db/install.xml`, `db/upgrade.php`, `db/upgradelib.php`, `db/access.php` and privacy provider unchanged.

## Not run

- Live Moodle 4.1.22 browser acceptance.
- Real production DB load/performance.
- Real KBA Theme desktop/mobile visual acceptance.
- PHPUnit/Behat.
