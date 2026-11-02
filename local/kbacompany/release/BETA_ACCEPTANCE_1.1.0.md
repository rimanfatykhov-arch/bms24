# BMS24 Calendar 1.1 — Beta Acceptance Checklist

Target: local_kbacompany 1.1.0-beta1 build2026101213

## A. Installation / upgrade
- [ ] Upgrade exact alpha20 -> beta1 on Moodle 4.1.22.
- [ ] Fresh install beta1 on clean test copy.
- [ ] No unexpected schema change beyond alpha18 meeting-resource schema already present in baseline.
- [ ] Scheduled tasks and message providers register successfully.
- [ ] Purge caches and confirm language strings render without `[[key]]` placeholders.

## B. Company isolation / roles
- [ ] Reader sees only permitted calendar projections.
- [ ] Manager sees permitted company/team projections.
- [ ] Foreign Company UUID substitution is rejected or yields no foreign data.
- [ ] Employee selector cannot be used to expose unauthorised employee workload.
- [ ] Private meeting title/resource occupancy remains hidden from non-participants.
- [ ] Other users' personal learning calendars are never queried for team analytics/finder/cron.

## C. Calendar views
- [ ] Month navigation across year boundary.
- [ ] Week navigation across month/year boundaries.
- [ ] Day planner renders timed and all-day events.
- [ ] Analytics view matches active month/filter/member scope.
- [ ] Mobile layout has no page-level destructive horizontal overflow; intended planner scrolling remains usable.

## D. Manual learning events
- [ ] Create personal event.
- [ ] Edit own manageable event.
- [ ] Delete own manageable event.
- [ ] Course/company event rights are revalidated server-side.
- [ ] Module-generated events remain read-only.
- [ ] Drag/drop changes only manageable manual events and preserves local time/duration.
- [ ] Recurring weekly/monthly/weekday series creates bounded canonical occurrences.

## E. BMS projections
- [ ] Tasks appear on correct due/start date.
- [ ] Meetings appear with canonical time and participant visibility.
- [ ] Battle Plans appear only at intended period boundary.
- [ ] Management checkpoints appear at planned date.
- [ ] Week/Month/Quarter/Year cycles display correct hierarchy and statuses.
- [ ] Calendar never mutates Task/Battle Plan/Cycle lifecycle directly.

## F. Meetings / planning workflow
- [ ] Meeting finder respects participant scope and does not expose private calendar details.
- [ ] Prefilled participants use scalar URL transport and survive create form validation.
- [ ] Planning meeting workflow links to Agenda / Decisions / Tasks / Close anchors.
- [ ] Accepted decision without execution task is surfaced as attention signal.
- [ ] Recurring BMS meetings create independent canonical meeting records.

## G. Meeting resources
- [ ] Resource CRUD scoped to Company.
- [ ] Archived resource remains readable on historical meetings.
- [ ] Capacity guard rejects over-capacity meetings.
- [ ] Overlap guard rejects double booking under concurrent attempts.
- [ ] Private occupancy does not reveal meeting title to non-participants.
- [ ] Online URL validates HTTP/HTTPS and renders safe join action.

## H. Notifications
- [ ] Meeting lead-time notification fires once per deterministic identity.
- [ ] Morning plan fires once per day/user/company when enabled.
- [ ] Task-due and checkpoint reminders respect preferences.
- [ ] DND suppresses claim creation during quiet period.
- [ ] Repeated cron does not duplicate CommunicationNotification.
- [ ] Popup/email delivery failure does not produce duplicate durable claim.

## I. Google Calendar
- [ ] OAuth issuer setup is discoverable from BMS UI.
- [ ] User OAuth connect/refresh succeeds.
- [ ] Selected Google calendar can be listed/read.
- [ ] External Google events are read-only projections.
- [ ] BMS Meeting -> Google create is idempotent.
- [ ] Subsequent sync updates same external event.
- [ ] BMS-exported Google event is not duplicated on reverse projection.
- [ ] No attendee email or OAuth secret is stored by local_kbacompany.
- [ ] Revoked token / rate-limit / network failure degrades safely.

## J. Acceptance exit criteria
Beta may progress to beta2 only when blocker/runtime defects are fixed and the complete live acceptance matrix is recorded. Beta1 itself does not assert production readiness.
