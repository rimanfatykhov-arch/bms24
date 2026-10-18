# KBA Connect v5.0.33.1

## Pairing Database Migration Repair

- Added idempotent repair migration for `local_bitrixsync_pairing`, `local_bitrixsync_corpconn`, and `local_bitrixsync_pairattempt`.
- Bumped plugin build so Moodle runs the repair even when v5.0.33 RC1 was already marked installed.
- No Bitrix24 or Corporate Dashboard business data is deleted or replaced.
