# Rollback and Safety

## Before automatic production

Keep:
- `v5_inbound_enabled = 0`
- automatic v5 engine traffic disabled until the controlled run is verified.

## Controlled run protections

- system capability `local/bitrixsync:manage`
- Moodle sesskey on web execution
- single configured controlled Deal ID
- exact typed confirmation
- preflight gating

## Existing compensation

The real `multi_course_engine` compensates a Corporate allocation created by the
current run if Moodle enrolment fails.

## Global rollback

Existing CLI:
`php local/bitrixsync/cli/rollback.php --confirm=LEGACY`

This returns production engine traffic to Legacy mode.
