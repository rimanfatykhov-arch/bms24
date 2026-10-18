# CHANGELOG 5.0.36.1.18

- Added explicit `bms_client_company_field` administrator setting.
- Added live read-only Company userfield enumeration for the selector.
- Restricted selector to single Bitrix24 boolean fields.
- Production BMS bridge now resolves only the exact configured FIELD_NAME.
- BMS diagnostics now resolves the same exact configured field.
- Missing/invalid selection pauses BMS company synchronization instead of falling back to heuristic field discovery.
- Version bumped to build 2026083628.
- No schema, ACL, task schedule, Corporate learning or Deal enrolment changes.
