# BMS24 1.1.0-rc29 build2026101783

## Battle Plan creation: week selection continuity

- The first contextual step of creating a Battle Plan now includes the week field alongside employee and position.
- The week travels through the GET transition and is normalised to its Monday through the existing `week_start()` service before rendering the draft form.
- Malformed week values are rejected server-side rather than silently replaced.
- Existing permission checks, plan uniqueness checks, approval lifecycle and task data are unchanged.
- Database schema unchanged; version-only Moodle upgrade savepoint.

## Acceptance steps
1. Select employee, position and a date on a future week. Continue. Confirm that date stays in the draft form and corresponds to that week.
2. Create a draft, then send for approval using existing flow.
3. Repeat without changing week and verify duplicate plan handling is preserved.
4. Test with restricted employee rights.

**QA scope:** static checks only; no live Moodle acceptance.
