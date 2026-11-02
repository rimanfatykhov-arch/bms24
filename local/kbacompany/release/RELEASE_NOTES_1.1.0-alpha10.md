# local_kbacompany 1.1.0-alpha10 — Meeting Time Finder

Build: 2026100706
Baseline: 1.1.0-alpha9 build2026100705

## Scope
- Multi-select employees, date and meeting duration.
- Suggest up to 8 common free intervals in 09:00–18:00 with 30-minute start granularity.
- Busy time is derived only from permission-scoped calendar projections already available to the current actor.
- Other employees' personal Moodle calendar events are never loaded.
- Suggested slot can prefill canonical BMS Meeting create form with start/end and participants.
- Finder is advisory; it does not reserve time or create records.

## Compatibility
No database schema, ACL or privacy-provider changes. Existing calendar CRUD and source projections remain unchanged.
