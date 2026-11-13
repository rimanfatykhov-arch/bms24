# BMS24 rc29 build2026101747 — employee execution card

- Adds a compact read-only eight-week execution summary to the employee Battle Plan tab.
- Uses the existing closure_discipline_trend projection (immutable close-time snapshots and per-task ACL).
- Shows done, unfinished, carried, completion share, weeks covered and legacy snapshot warning.
- Does not claim an on-time completion metric; no schema changes or duplicated tasks.
- Static QA only; Moodle live acceptance not performed.
