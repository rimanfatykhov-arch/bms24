# local_kbacompany 1.1.0-alpha20 build2026101212

## Calendar Analytics

This release adds a read-only Analytics view to the BMS24 Calendar.

Metrics are calculated from already-authorised calendar projections only and are not persisted. The calendar does not create an employee performance score.

### Metrics
- Meeting count and meeting hours.
- Meeting density against the 09:00–18:00 weekday working window.
- Estimated focus-time capacity (working window minus meeting time).
- Visible overdue canonical tasks whose due date is inside the selected month.
- Meeting-heavy days (4+ meeting hours or an overlap).
- Days with overlapping meetings.
- Current regular-management attention signals from the existing weekly acceptance/readiness model.
- Team workload table for authorised organisation managers.

### Thresholds
The UI treats >=75% timed occupancy or any overlap as a workload warning. These are explicit presentation rules, not HR evaluation or persisted KPI values.

### Security and boundaries
- Company scope is preserved before fetch.
- Team analytics use only already-visible BMS projections.
- Other employees' personal learning calendars are not loaded.
- No new analytics table or background write is introduced.
- Database schema, ACL and privacy storage contracts are unchanged.

### Live acceptance
NOT RUN: real Moodle 4.1.22 browser rendering, production DB performance, large-company analytics, mobile layout and real customer acceptance.
