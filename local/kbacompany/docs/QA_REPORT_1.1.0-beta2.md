# QA Report — local_kbacompany 1.1.0-beta2 build2026101214

## PASS
- PHP lint: 356/356 source files.
- JavaScript syntax: 11/11 files.
- RU/EN language parity: 7096/7096; duplicate keys 0.
- XML parse: PASS.
- Protected db/install.xml, db/upgrade.php, db/upgradelib.php, db/access.php and privacy provider: byte-identical beta1.
- Reminder cron overlap lock contract: PASS.
- Reminder recipient DB recordset streaming contract: PASS.
- Malformed timezone fallback contract: PASS.
- Moodle core event pagination: 500/page, 5000 hard limit: PASS.
- DST-invalid local datetime exact-wall-clock guard: PASS; Europe/Berlin 2026-03-29 02:30 test normalises to 03:30 and is therefore rejected by the guard.
- Drag/drop local-time DST guard: PASS.

## NOT RUN
- Live Moodle 4.1.22 install/upgrade.
- PHP 7.3 runtime.
- Production MySQL/MariaDB load and query plans.
- Real concurrent Moodle cron processes.
- Real Google OAuth / Calendar API.
- Browser/mobile visual acceptance.
- Large-company production acceptance.
