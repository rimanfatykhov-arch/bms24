# BMS24 RC29 build2026101766 — Bulk decision status

- Cross-week decision registry: select up to 20 decisions on one page and change their execution status with a confirmation dialog.
- Server-side company and management permissions validated; individual updates delegated to canonical week/task visibility and revision control.
- Each successful update creates a new append-only protocol revision. No Task Center changes or new DB tables.
- Stale/inaccessible/not-currently-visible entries are skipped; counts are reported after action.
- Only current page decisions are selectable. No mass owner or deadline edits in this release.
- Live Moodle acceptance: NOT RUN.
