# local_kbacompany 1.1.0-alpha19 — Google Calendar Provider

Build: 2026101211
Baseline: 1.1.0-alpha18 build2026101210

## Scope
- Provider-neutral external calendar boundary with Google Calendar as the first provider.
- Uses Moodle core OAuth2; local_kbacompany stores no Google credentials.
- Per-user opt-in configuration: Google issuer, target calendar ID, import toggle and integration toggle.
- Read-only Google event projection into the BMS24 calendar.
- Explicit idempotent export/update of permission-scoped BMS Meetings for the next 90 days.
- Existing local_kbacompany_extmap stores BMS meeting ↔ Google event identity, scoped by Company + user + calendar.
- BMS-exported meetings are suppressed from the Google import projection to avoid duplicates.
- No attendee names/emails are exported to Google.
- No background sync and no Google-to-BMS mutations in alpha19.

## Deliberate limits
- Alpha19 synchronises BMS Meetings only; tasks, checkpoints and Battle Plan remain BMS-only.
- Google import is read-only.
- External event deletion reconciliation and incremental syncToken processing are deferred.
- User must connect Google through a Moodle OAuth2 Google issuer configured by an administrator.

## Live gates
NOT RUN: live Moodle 4.1.22 OAuth redirect, real Google Calendar API, real create/update event, token refresh, timezone/DST, revoked consent, rate limits, production acceptance.
