# local_kbacompany 1.1.0-alpha8

Build: **2026100704**
Title: **Calendar Day Planner & Conflict Awareness**
Baseline: **1.1.0-alpha7 build2026100703**

## Scope

The BMS24 Calendar Day view now renders a vertical hourly planning surface over the existing authorised federated calendar projection. It does not create a new event store.

### Added

- hourly Day timeline;
- timed-event blocks positioned by local user time;
- separate all-day strip;
- occupied-time summary from actual event durations;
- overlap/conflict detection and visual warning;
- quick-add links for each hour, pre-filling the manual Moodle event editor;
- adaptive visible hour range around the selected day's events.

### Boundaries

- Moodle Calendar remains the source of truth for Moodle events;
- BMS tasks, meetings, Battle Plans and checkpoints remain read-only projections here;
- overlap diagnostics are read-only and are not persisted;
- drag/drop mutation rules from alpha7 are unchanged;
- no database schema, capability or privacy changes.

## Live acceptance

NOT RUN: Moodle 4.1.22 browser rendering, real KBA Theme desktop/mobile, real mixed-source day, real overlap cases and hourly quick-create.
