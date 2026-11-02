# BMS Calendar Today — 1.1.0-alpha11

Alpha11 adds a read-only personal **Today** projection to the main BMS24 workspace.

## Scope

- Reuses the existing permission-scoped BMS Calendar projections.
- Shows today's remaining events, meetings and tasks due today.
- Highlights events within the next hour.
- Shows the count of tomorrow's management-cycle checkpoints.
- Links directly to the Day view of BMS Calendar.

## Boundaries

- No new database table or reminder storage.
- No Moodle notification/message is sent in this release.
- Page loads are side-effect free.
- Other users' personal Moodle calendars are never read.
- Canonical Tasks, Meetings, Battle Plans and Management Cycles remain owned by their existing domain services.

Background/push delivery is intentionally deferred until a durable idempotency/deduplication contract is defined.
