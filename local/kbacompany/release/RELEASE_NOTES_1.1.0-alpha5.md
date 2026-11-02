# local_kbacompany 1.1.0-alpha5 — BMS Calendar Usability

Build: 2026100701
Baseline: 1.1.0-alpha4 build2026100618

## Scope
- BMS event detail card inside calendar workspace.
- Search filter by event name/source.
- Filter: all / manual / Moodle-generated events.
- Quick create (+) from an exact calendar day.
- Filters preserved across month navigation, event detail and CRUD.
- Moodle Calendar remains the source of truth; no new storage.
- Generated events remain read-only in BMS.

## Security / compatibility
- Existing Company scope rules remain unchanged.
- CRUD still uses Moodle Calendar API, sesskey and capability checks.
- No DB schema, ACL or privacy changes.
