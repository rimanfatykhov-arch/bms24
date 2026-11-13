# local_kbacompany 1.1.0-alpha18 — Meeting Resources & Availability

Build: 2026101210

- Added canonical Company-scoped Meeting Resource registry (room/place).
- Added meeting `resourceuuid` and `onlineurl` fields with additive Moodle upgrade.
- Added resource CRUD (create/edit/archive) and seven-day occupancy view.
- Added conflict-safe booking with a per-resource lock and overlap validation.
- Added resource capacity guard for meeting creation, reassignment and participant addition.
- Added HTTP/HTTPS online meeting link validation and Join Online action.
- Busy resource periods do not reveal private meeting titles to non-participants.
- Existing meetings remain backward compatible with empty resource/link fields.
- Communication Center BMS object selector now renders canonical product names instead of internal technical keys.
- No separate booking calendar or second source of truth introduced.
