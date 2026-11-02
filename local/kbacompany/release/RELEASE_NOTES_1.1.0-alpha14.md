# local_kbacompany 1.1.0-alpha14 — Recurring Events & Regular Meetings

Build: `2026101204`
Baseline: exact `1.1.0-alpha13.1 build2026101203`.

## Scope

- Added explicit recurrence controls to manual BMS Calendar event creation.
- Added recurrence controls to manual BMS Meeting creation.
- Supported recurrence modes: weekly, monthly, selected weekdays; count is bounded to 52 occurrences.
- Each occurrence is created as an ordinary canonical record in its owning subsystem:
  - Moodle Calendar events remain standard Moodle calendar events.
  - BMS meetings remain standard BMS meetings with their own participants, discussion thread, minutes, decisions and tasks.
- Existing edit/delete lifecycle remains per occurrence. Bulk edit/delete of a series is intentionally not introduced in alpha14.
- Management-cycle sourced meetings and discussion-sourced meetings do not accept recurrence expansion; only manual meetings can create a series.
- No recurrence database table and no second calendar/meeting source of truth were introduced.

## Compatibility

- Existing single event and single meeting creation remain unchanged when Repeat = None.
- Existing events and meetings require no migration.
- Schema, ACL and privacy provider are unchanged.

## Live status

NOT RUN: Moodle 4.1.22 runtime acceptance, real recurring event creation, real recurring meeting creation, timezone/DST visual acceptance and mobile acceptance.
