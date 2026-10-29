# Google Calendar Provider — 1.1.0-alpha19

Canonical rule: BMS24 owns management semantics; Google Calendar is an external calendar provider.

## Direction
- Google → BMS24: read-only external event projection for the connected user.
- BMS24 → Google: explicit upsert of BMS Meeting events.

## Identity
Provider: `google_calendar`.
Existing `local_kbacompany_extmap` is reused; no calendar-specific mapping table is introduced.
Namespace is scoped by user and target Google calendar. Local reference is `meeting:<BMS Meeting UUID>`.

## Security
- Current Company context must be valid before every projection/sync.
- Only the signed-in user's OAuth account is used.
- Other users' Google calendars are never loaded.
- Meeting export uses the existing permission-scoped Meeting calendar projection.
- Attendee emails are not exported.
- OAuth tokens stay in Moodle core OAuth2 storage.

## Alpha19 non-goals
No background sync, webhook/watch channel, incremental `syncToken`, Google-origin mutation of BMS objects, or Outlook provider yet.
