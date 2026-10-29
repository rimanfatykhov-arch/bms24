# BMS Calendar Recurrence — 1.1.0-alpha14

The alpha14 recurrence model deliberately expands a requested series into ordinary canonical occurrences at creation time.

There is no `series` table and no hidden recurrence engine. This prevents BMS24 from becoming a second source of truth for Moodle Calendar or BMS Meeting.

## Supported rules

- none;
- weekly;
- monthly on the same day-of-month where that day exists;
- selected weekdays (ISO Monday=1 … Sunday=7);
- maximum 52 occurrences per explicit create request.

Monthly recurrence skips months that do not contain the original day number instead of silently moving the meeting/event to another date.

## Lifecycle

Each occurrence is independent after creation. Edit, delete, attendance, minutes, decisions and task generation use the existing canonical lifecycle of that occurrence.

Bulk series mutation is intentionally deferred to a future release because it requires explicit series identity, optimistic concurrency and partial-history semantics.
