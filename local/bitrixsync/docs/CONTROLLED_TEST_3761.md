# Controlled Test — Deal 3761

Expected source:
- Deal: 3761
- Company: ООО АЛТРЕЙД
- Expected mapped courses from current coursemap:
  - Product 235 → Course 8
  - Product 237 → Course 2
  - Product 227 → Course 13

## Preflight must prove

- Bitrix deal is readable.
- Company exists.
- Contact exists.
- Moodle user resolves or can be created.
- Every product row resolves to a course.
- Corporate API v1.9+ is compatible.
- In `enforce` mode, Corporate is writesafe and licence API is available.
- If CRM writeback is enabled, CRM field registry is ready.

## REAL execution

The real controlled execution is intentionally explicit:

`--confirm=EXECUTE_3761`

The existing v5 engine then performs:
- Corporate allocation (if enabled)
- Moodle enrolment
- CRM writeback (if enabled)
- real `local_bitrixsync_dealrun` audit

## Success criteria

- `failed = 0`
- every product row is `enrolled`, `already_enrolled`, or `already_processed`
- Corporate status is allocated/already_allocated where required
- CRM writeback has no errors when enabled
- Deal Run status is `completed`
