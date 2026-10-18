# RC24 — Full Regression Acceptance Matrix

| Gate | Result | Evidence / Required next step |
| --- | --- | --- |
| PHP syntax, 363 files | PASS | Offline PHP lint |
| JavaScript syntax | PASS | Offline node --check source JS |
| db/install.xml parse | PASS | Offline XML parser |
| RU/EN parity, 7575 strings | PASS | Exact key equality and no duplicates |
| Version/upgrade chain | PASS (STATIC) | Source contract checks, no runtime upgrade |
| Critical routes and CSRF source guards | PASS (STATIC ONLY) | Static checks do not prove runtime authorization |
| Moodle PHPUnit (80 discovered) | NOT RUN | Moodle PHPUnit install/test database required |
| Moodle Behat and browser CRUD | NOT RUN | Test Moodle / KBA Theme required |
| RC22 company isolation A/B roles/files/API | NOT RUN | Negative permission tests required |
| RC23 query EXPLAIN and large-data timing | NOT RUN | Production-like MySQL 5.7 data required |
| RC21 App Shell mobile/desktop visual consistency | NOT RUN | KBA Theme browser verification required |
| Upgrade/backup/restore/rollback | NOT RUN | Staging rehearsal with backups required |

**FINAL release blocked until required runtime gates pass and exceptions are explicitly accepted.**
