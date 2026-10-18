# Static test report — 0.26.2

- Version and XMLDB versions match build `2026090273`.
- The additive upgrade adds only reminder-delivery state fields to implementation tasks.
- The scheduled task is registered once per day and remains non-blocking.
- Active statuses and configured deadline window are filtered server-side.
- Upcoming reminders target the task owner; overdue escalation also targets the curator and authorised managers.
- Reminder state prevents duplicate delivery and is reset when the deadline changes.
- Completed and cancelled tasks are excluded.
- Russian and English language packs remain unique and in parity.
- `install.xml` parses successfully; referenced literal plugin strings are present.
- ZIP integrity and plugin root are validated during packaging.

The local build environment does not provide the PHP CLI, so PHP lint, cron execution and notification delivery must be verified on the target Moodle server.
