# RC22 — Security / Company Isolation acceptance

- [ ] Two-company matrix: manager A cannot list/read/write Company B entities.
- [ ] Former employee loses access to threads, notifications, task and document files.
- [ ] Verify Google connect GET rejected, POST without sesskey rejected, valid POST opens OAuth.
- [ ] Verify file item IDs zero, negative, nonnumeric and foreign company are denied.
- [ ] CSRF checks for other mutating routes and REST entry points.
- [ ] Test Moodle session, authorisation and backup/restore, capture evidence.

No live test has been run. Static checks are not proof of Company Isolation.
