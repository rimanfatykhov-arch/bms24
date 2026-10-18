# BMS Calendar Notification Preferences — 1.1.0-alpha13

## Contract

Notification preferences configure delivery only. They do not change visibility or mutate canonical Tasks, Meetings, Battle Plans, Management Cycles or Moodle Calendar events.

### Company policy

Organisation managers may define default reminder behaviour for the current Company. Company UUID remains the security boundary. Defaults are stored without adding a new BMS domain table.

### Personal override

Every active Company member may either inherit Company defaults or explicitly override them for that Company. Users cannot edit another user's reminder preferences.

### Quiet hours

Do-not-disturb is evaluated in the recipient's Moodle timezone before any durable notification claim is created. Therefore suppressed reminders do not create hidden CommunicationNotification rows.

### Delivery channels

BMS emits the existing `calendar_reminder` Moodle message provider. Processor preferences (popup/email and other Moodle processors) remain owned by Moodle and are linked from the BMS settings page.

### Idempotency

The alpha12 deterministic CommunicationNotification UUID mechanism remains unchanged. Preference evaluation happens before claim; repeated cron executions cannot duplicate an already claimed reminder.
