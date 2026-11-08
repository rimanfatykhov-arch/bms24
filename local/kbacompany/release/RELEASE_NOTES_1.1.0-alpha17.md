# local_kbacompany 1.1.0-alpha17 — Planning Meeting Workflow

Build: `2026101209`
Baseline: `1.1.0-alpha16 build2026101208`

## Scope

The BMS Calendar now treats a planning meeting as a management execution point without introducing a second meeting object. The read-only Calendar projection exposes the existing canonical chain:

`Meeting → Agenda → Decision → Task`

For participant-visible meetings in the next seven days Calendar shows:
- agenda item count and open items;
- decision count and proposed decisions;
- task count and accepted decisions that still have no execution task;
- direct navigation to Agenda / Decisions / Tasks / Closure in the canonical meeting workspace.

The meeting workspace itself displays the same workflow strip and readiness-to-close status.

## Boundaries

- No new DB tables.
- No duplicate planning-meeting entity.
- Calendar never creates agenda items, decisions or tasks implicitly.
- Calendar does not start or close meetings.
- Meeting mutations remain in `meeting_service` and existing POST controllers with their current permission and revision guards.
- Visibility remains Company-scoped and explicit-participant-scoped.
