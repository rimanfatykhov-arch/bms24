# BMS Calendar — Unified Management Projection

The calendar is an aggregator, not an owner of management records.

| Source | Canonical owner | Calendar date | Access rule |
|---|---|---|---|
| Moodle event | Moodle Core Calendar | `timestart` | Moodle visibility |
| Task | BMS Task Center | `duedate`, fallback `startdate` | Task projection access |
| Meeting | BMS Meetings | `startsat` | active participant |
| Battle Plan | BMS Battle Plan | `periodstart` | Battle Plan access |
| Checkpoint | Management Cycle | `plannedat` | management capability |

Mutations of BMS records are performed only in their canonical workspace. Moodle manual-event CRUD remains the only mutation path exposed directly from Calendar.
