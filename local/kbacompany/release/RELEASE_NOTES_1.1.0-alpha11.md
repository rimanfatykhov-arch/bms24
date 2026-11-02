# local_kbacompany 1.1.0-alpha11 — Calendar Today & Reminder Signals

Build: 2026100707
Baseline: 1.1.0-alpha10 build2026100706

## Added

- Personal “Today” card on the BMS24 main workspace.
- Read-only reminder signals for events in the next hour, tasks due today and tomorrow's checkpoints.
- Direct link to the BMS Calendar Day view.

## Architecture

The Today card reuses the existing permission-scoped Calendar projections. It does not copy events, create reminder rows or send background notifications. This keeps GET/page rendering side-effect free and avoids duplicate notifications before a durable deduplication contract is introduced.

## Compatibility

No database schema, ACL or privacy storage changes.
