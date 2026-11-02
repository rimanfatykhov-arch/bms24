# BMS24 Calendar 1.1.0-beta2 — Performance / Cron / Timezone Hardening

Beta2 keeps the beta1 feature freeze and hardens runtime behaviour for Moodle 4.1 / PHP 7.3+.

## Changes

- Moodle core calendar reads are paginated in bounded 500-event pages with a 5,000-event hard ceiling per month request.
- Reminder cron uses a Moodle lock so overlapping scheduler runs do not process the same recipient set concurrently.
- Recipient scanning uses a DB recordset instead of loading all active memberships into memory.
- A malformed user timezone no longer aborts the reminder run; the cron falls back to the current Moodle/user timezone.
- Local datetime inputs reject silent PHP DST normalisation. Non-existent local wall times (for example, a skipped DST hour) are rejected instead of being shifted unexpectedly.
- Drag/drop date moves apply the same exact-local-time DST guard.

No new calendar features, tables, capabilities, routes, or provider contracts are introduced in beta2.
