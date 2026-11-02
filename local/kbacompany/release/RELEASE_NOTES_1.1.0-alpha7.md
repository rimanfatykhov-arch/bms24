# local_kbacompany 1.1.0-alpha7 — Calendar Planning Views

Build: `2026100703`
Baseline: `1.1.0-alpha6 build2026100702`
Baseline INSTALL SHA256: `336c1d32f63a0aebd31c6b92b0d050b827bf1114bd94e6dffd8e783ade921beb`

## Purpose

Make the unified BMS24 calendar useful for daily and weekly planning while preserving the existing federated-source architecture.

## Added

- Month / Week / Day view switch inside the BMS24 Calendar workspace.
- Week navigation moves by seven days and correctly spans month boundaries.
- Day navigation moves by one day.
- Existing source/search/type filters persist across calendar views.
- Quick-create remains available from visible days in every view.
- Authorised manual Moodle events can be dragged to another visible day.
- Drag/drop preserves the event's local clock time and duration.

## Mutation boundary

- Drag/drop mutates only manual Moodle Calendar events already authorised by the existing calendar CRUD guard.
- Module-generated, subscription and group events remain read-only.
- BMS Tasks, Meetings, Battle Plans and Management Checkpoints remain read-only calendar projections and are never moved by drag/drop.
- Move requests use POST + sesskey and re-run server-side event/company/capability checks; the browser drag state is never trusted as authorisation.

## Architecture

- No new event table or scheduling engine.
- Moodle Calendar remains canonical for Moodle events.
- BMS domains remain canonical for Tasks, Meetings, Battle Plans and Management Cycles.
- Week/day windows compose the same permission-scoped monthly projections and deduplicate by canonical identity.
- No schema, ACL or privacy storage changes.

## Not run

- Live Moodle 4.1.22 browser drag/drop acceptance.
- Real KBA Theme desktop/mobile visual acceptance.
- Production DB performance/load.
- PHPUnit/Behat.
