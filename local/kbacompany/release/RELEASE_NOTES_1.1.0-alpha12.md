# local_kbacompany 1.1.0-alpha12 — Calendar Notification Engine

Build: `2026101201`

Baseline: exact `1.1.0-alpha11 build2026100707`.

## Scope

Adds durable idempotent calendar reminder delivery without introducing a second calendar or reminder store.

Signals:
- participant meeting entering the 45–75 minute window;
- own canonical BMS task due today;
- management-cycle checkpoint planned for tomorrow when the recipient already has management-cycle visibility;
- one 08:00-local daily summary per Company/member/day.

Delivery:
1. claim a deterministic UUID in existing `local_kbacompany_commnotif`;
2. surface it in BMS Communication Center;
3. best-effort Moodle `calendar_reminder` notification.

Repeated cron runs are idempotent at the BMS notification boundary. A Moodle transport failure keeps the durable BMS notification and is not retried automatically, preferring no duplicate popup over at-least-once external delivery.

## Security / privacy

- canonical active Company membership is selected in SQL before recipient processing;
- Task projection is re-filtered to the recipient as assignee;
- Meeting projection is participant-scoped;
- Management Cycle checkpoint projection keeps its existing capability boundary;
- the scheduled task never reads another user’s personal Moodle calendar;
- no business object dates/statuses are changed.

## Storage

No new tables or fields. Existing CommunicationNotification storage is reused. `db/messages.php` and a scheduled task declaration are new.

## Live status

Live Moodle cron/message delivery is NOT RUN in the build environment and must be accepted on Moodle 4.1.22 before production use.
