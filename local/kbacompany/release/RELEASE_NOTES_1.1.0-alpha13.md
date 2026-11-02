# local_kbacompany 1.1.0-alpha13 — Calendar Notification Preferences

Build: 2026101202
Baseline: 1.1.0-alpha12 build2026101201

## Scope

Adds explicit BMS Calendar notification preferences on top of the alpha12 Notification Engine.

- Personal settings per user and Company.
- Optional inheritance from Company defaults.
- Company default policy editable only by organisation managers.
- Meeting reminder lead: 15 / 30 / 60 / 120 minutes.
- Configurable morning notification hour: 06:00–11:00.
- Independent toggles for morning digest, tasks due today and tomorrow checkpoints.
- Do-not-disturb interval with overnight range support.
- Direct link to Moodle notification processor preferences for popup/email channel control.
- Cron applies effective preferences before claiming an idempotent CommunicationNotification.
- Quiet hours suppress delivery without mutating Tasks, Meetings, Cycles or Calendar events.

## Storage

No new plugin table is introduced.

- Company defaults use Moodle `config_plugins`, addressed by a deterministic Company UUID hash.
- Personal overrides use Moodle `user_preferences`, addressed by a deterministic Company UUID hash.
- Existing CommunicationNotification deterministic UUIDs remain the reminder deduplication mechanism.

## Compatibility

Existing users inherit the Company policy. The default Company policy reproduces alpha12 behaviour: notifications enabled, 60-minute meeting reminder, 08:00 morning signals, tasks due today and tomorrow checkpoints enabled, quiet hours disabled.

Moodle remains responsible for choosing enabled notification processors such as popup and email.

## Live status

NOT RUN: live Moodle 4.1.22 upgrade, real cron delivery, popup/email processor acceptance, timezone/DST acceptance and production acceptance.
