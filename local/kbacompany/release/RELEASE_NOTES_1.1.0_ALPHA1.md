# local_kbacompany 1.1.0-alpha1 — BMS Calendar Workspace

Build: 2026100615
Baseline: exact 1.0.0-rc2 build2026100614.

## Scope
- Adds a BMS24 **Calendar** workspace and sidebar item.
- Moodle Core Calendar remains the source of truth.
- BMS reads only events from courses explicitly linked to the current Company.
- Personal/site-wide/other-company events are not injected into Company context.
- Month view, month navigation and upcoming-event list.
- Direct link to the full Moodle Calendar.
- No calendar tables, event copies, hidden writes, migrations or ACL changes.

## Security boundary
Company UUID is resolved first. Course IDs come only from the company's Corporate Learning catalogue or existing explicit BMS course-source bindings; there is no global course scan.

## Live acceptance still required
Moodle 4.1.22 runtime, real calendar visibility, KBA Theme desktop/mobile, group events, course activity due dates and cross-company isolation are NOT RUN locally.
