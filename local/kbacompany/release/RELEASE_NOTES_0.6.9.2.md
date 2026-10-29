# BMS v0.6.9.2 — Employee Position Projection Hotfix

Build: `2026092132`

## Fixed
- Inbound BMS employee sync accepts `position` from KBA Connect.
- Non-empty Bitrix Contact `POST` fills or updates Moodle custom profile field `position`.
- Empty Bitrix `POST` is non-destructive.
- Inbound result exposes `position_projection` and `profile_position`.

## Boundary
The Moodle profile field is only a display/compatibility projection for KBA Corporate. It does not create or assign canonical BMS Positions.

## Companion
Use KBA Connect `5.0.36.1.23` or newer to hand off Bitrix Contact `POST`.

## Unchanged
No DB schema, ACL, enrolment, competency or Cloud-contract changes.
