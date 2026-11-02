# BMS24 RC29 / build2026101643 - W3.2 Recurring Series Acceptance

**Target:** test Moodle, KBA Theme and Boost where available. **Status:** live Moodle acceptance not run in this workspace.

## Before Install

- [ ] Record Moodle version, PHP version/extensions, DB version/collation, active theme and current `local_kbacompany` build.
- [ ] Backup DB, moodledata and current `local/kbacompany`.
- [ ] Confirm package ZIP root is exactly `kbacompany/`.

## Upgrade

- [ ] Install `INSTALL_ME_local_kbacompany_v1.1.0-rc29_build2026101643.zip`.
- [ ] Run Moodle upgrade/Notifications; expected upgrade is no-DDL savepoint `2026101643`.
- [ ] Purge Moodle caches.

## Legacy Mode

- [ ] Keep `local_kbacompany/bms24_shell_mode=legacy`.
- [ ] Open `recurring_tasks.php?uuid=<company_uuid>` list/create/detail/edit.
- [ ] Confirm old layout, forms, buttons, status filter, detail actions and occurrence/history blocks still work.
- [ ] Confirm no new UI is silently enabled for companies.

## Owned Mode

- [ ] Switch `local_kbacompany/bms24_shell_mode=owned` and purge caches.
- [ ] Open recurring registry; header/actions use BMS24 owned UI, status filter uses UI Kit, table is responsive.
- [ ] Verify statuses `draft`, `active`, `paused`, `archived` render correctly.
- [ ] Create series: title, product, assignee, duty position selector, cadence, weekdays, advanced fields, hidden `sesskey` and stream from Battle Plan context.
- [ ] Edit series: stale revision conflict, required fields, library provenance hiding/preservation and `dutyshareconfirm` still behave as before.
- [ ] Detail view: schedule details, next dates, occurrence journal, revision history and technical details remain visible according to existing permissions.
- [ ] Actions: activate, pause, generate-today and archive confirmation require POST, `sesskey`, `revision` and existing permissions.
- [ ] Check direct URL and direct POST with another company UUID: access denied/no foreign data exposed.
- [ ] Verify manager vs owner vs assignee vs reviewer visibility.
- [ ] Verify mobile widths 390/768/1024/1440 px, keyboard focus and table scroll.

## Recurrence / Safety

- [ ] Opening owned UI does not generate occurrences.
- [ ] Cron/manual generation remains idempotent: one occurrence per company + series + period.
- [ ] Existing approved Battle Plan links and generated task UUIDs remain unchanged.
- [ ] Position coverage changes effective executor only for explicitly position-bound open work; original assignee is preserved.

## Exit Criteria

- [ ] Record PASS/FAIL with date, environment and anonymized users/companies.
- [ ] Attach screenshots/browser console notes for legacy and owned modes.
- [ ] Do not treat this as production FINAL until DB, cron, RBAC/security, restore and browser gates pass.
