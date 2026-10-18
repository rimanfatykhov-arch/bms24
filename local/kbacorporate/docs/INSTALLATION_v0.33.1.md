# INSTALLATION — v0.33.1

## Prerequisites

- Moodle 4.0 or later supported by the existing installation;
- currently installed `local_kbacorporate` version no later than the supplied build;
- database and `moodledata` backup;
- administrator access.

## Upgrade

1. Back up database, `/local/kbacorporate` and `moodledata`.
2. Install the full ZIP through Moodle plugin installer.
3. Complete the database upgrade to build `2026090811`.
4. Purge caches.
5. Open Integration Health Center and verify both new tables.
6. Execute the pilot cases in `TEST_REPORT_0.33.1.md`.

## Expected schema changes

Moodle creates `local_kbacorp_impl_evid` and `local_kbacorp_impl_evlog`. Existing task and legacy evidence records are not modified.

## Rollback

A file-only downgrade is not supported. Restore the database, plugin directory and `moodledata` backup for a full rollback.
