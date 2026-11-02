# BMS24 RC27 — Work Calendar Permissions — build2026101458

Baseline: RC26/build2026101357.

- Company-scoped employee WORK calendar, distinct from personal Moodle calendar events.
- New isolated work-event list and creation form linked from employee Calendar tab.
- Explicit independent canview/canedit/cancomment grants per company + owner + Moodle grantee ID; owner and authorised People manager can administer grants; empty rights revoke.
- Comments on individual work events, company-checked on every operation.
- POST + sesskey and server ACL on all mutations; normalise company and require membership for owner and grantee.
- Three new tables: local_kbacompany_wcalev, local_kbacompany_wcalacl, local_kbacompany_wcalmsg. New upgrade step 2026101458. No personal Moodle events migrated or exposed.

Known limitations: first iteration displays work events for CURRENT MONTH only; no calendar grid, recurring entries, delegation to an external account, delete UI or notifications. Calendar grants use known Moodle ID; directory picker is pending. Work entries are not yet merged into universal Moodle calendar projection. Granting comment without read permits comment via protected API but doesn't expose the event list. Managers' rights rely on permission_service::can_manage_people. Full live Moodle/MySQL/ACL/Behat tests NOT RUN. This is RC, not FINAL.
