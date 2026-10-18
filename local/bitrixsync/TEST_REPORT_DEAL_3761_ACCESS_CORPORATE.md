# TEST REPORT — Deal 3761

Target contact: 12657 — Крючкова Эльмира Фаритовна
Existing Moodle User after previous hotfix: 41

Expected REAL re-run:
- User 41 is not duplicated.
- If user 41 has never logged in and access was never sent, a fresh temporary password is generated once.
- Login remains email: altreid.torg@bk.ru.
- Access email is sent.
- Contact + Deal Timeline receive an access-sent entry without password.
- Employee is attached to ООО «АЛТРЕЙД» in KBA Corporate Dashboard.
- CRM contact field `Moodle — сотрудник Corporate Dashboard` becomes `Да` on successful attachment.
- Existing four course enrollments remain idempotent.
