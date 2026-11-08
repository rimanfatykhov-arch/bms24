# BMS24 Calendar 1.1.0-rc1 — Live Acceptance Checklist

Build: **2026101215**\
Release: **1.1.0-rc1**\
Status before live run: **NOT ACCEPTED / candidate only**

## 1. Installation and upgrade
- [ ] Upgrade from 1.1.0-beta2 completes without exception.
- [ ] Fresh install completes without exception.
- [ ] Plugin reports 1.1.0-rc1 / build 2026101215 / MATURITY_RC.
- [ ] Scheduled tasks register and execute.
- [ ] Existing meetings, tasks, cycles, resources and external mappings remain readable.

## 2. Company isolation and permissions
- [ ] Company A user cannot read Company B calendar projections by URL/filter manipulation.
- [ ] Company A user cannot edit/delete Company B manual calendar events.
- [ ] Reader/employee role cannot perform manager-only actions.
- [ ] Meeting/resource operations re-check capability and Company scope on mutation.
- [ ] Team workload never exposes another employee's private learning calendar.
- [ ] Private meeting occupancy hides title/link from non-participants.

## 3. Core calendar UX
- [ ] Month view loads and navigates correctly.
- [ ] Week view loads and navigates correctly.
- [ ] Day planner positions timed events correctly.
- [ ] Analytics view loads without mutation.
- [ ] Filters/sources preserve current Company and date context.
- [ ] Mobile layout remains usable at narrow widths.
- [ ] User-facing UI uses BMS24 vocabulary, including “Обучение” instead of product name Moodle.

## 4. Manual calendar event CRUD
- [ ] Create manual event.
- [ ] Edit manual event.
- [ ] Delete manual event with confirmation.
- [ ] Component/activity/subscription/group events remain read-only.
- [ ] Drag/drop updates only editable manual events.
- [ ] Invalid/DST-normalized local time is rejected rather than silently shifted.

## 5. Recurrence
- [ ] Weekly recurrence expands into canonical occurrences.
- [ ] Monthly recurrence preserves day-of-month and skips invalid dates.
- [ ] Selected-weekday recurrence works.
- [ ] Maximum occurrence guard is enforced.
- [ ] Existing occurrences remain ordinary canonical objects.

## 6. Meetings and regular management
- [ ] Meeting Time Finder returns valid common windows.
- [ ] Finder prefill opens the canonical meeting form.
- [ ] Management-cycle Week/Month/Quarter/Year projections display correctly.
- [ ] Regular-management block opens canonical launch/control/closure actions.
- [ ] Planning workflow navigates Agenda → Decisions → Tasks → Closure.
- [ ] Calendar itself does not silently mutate cycle/meeting state.

## 7. Meeting Resources
- [ ] Create/edit/archive Meeting Resource.
- [ ] Assign resource to a meeting.
- [ ] Capacity guard rejects oversized meeting.
- [ ] Overlap guard rejects double booking.
- [ ] Concurrent booking attempt does not create two valid overlapping reservations.
- [ ] Online URL validates HTTP/HTTPS and opens correctly.

## 8. Notifications and cron
- [ ] Scheduled task runs without fatal error.
- [ ] Parallel cron run is prevented by lock.
- [ ] Meeting reminder is deduplicated.
- [ ] Morning summary is deduplicated.
- [ ] Task/checkpoint signals respect preferences.
- [ ] DND suppresses notification creation as designed.
- [ ] Malformed user timezone does not abort processing of other users.

## 9. Google Calendar provider
- [ ] Google OAuth2 issuer can be selected and connected.
- [ ] Selected calendar can be read.
- [ ] Google events appear as read-only provider projections.
- [ ] BMS meeting export creates a Google event.
- [ ] Re-sync updates existing mapped event instead of duplicating it.
- [ ] BMS-exported event is not duplicated on reverse projection.
- [ ] Token refresh/error path fails safely and visibly.
- [ ] Timezone/DST remains correct across Google round-trip.

## 10. Performance / large-company checks
- [ ] Event pagination works beyond one page.
- [ ] Monthly read cap fails/degrades predictably instead of exhausting memory.
- [ ] Reminder recipient scan remains streaming.
- [ ] Calendar response remains acceptable with representative large-company data.

## 11. Regression outside Calendar
- [ ] BMS navigation loads.
- [ ] Employees and positions load.
- [ ] Tasks load.
- [ ] Meetings load.
- [ ] Battle Plan loads.
- [ ] Management Cycles load.
- [ ] Process Architecture/IDEF0/BPMN load.
- [ ] Learning contour entry loads.
- [ ] Communication Center loads.

## RC1 exit rule
RC1 may advance only after the mandatory live gates above pass on the target Moodle 4.1.22 environment. Any functional or security defect found in live acceptance produces an rc2 hotfix/candidate; do not call 1.1.0 final before PASS.
