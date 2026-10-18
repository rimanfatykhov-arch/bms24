# QA Report — local_kbacompany 1.1.0-beta1 build2026101213

## Static / local gates
- PHP lint: PASS — 356/356 source PHP files.
- JavaScript syntax: PASS — 3 source AMD JavaScript files checked with Node syntax parser.
- RU/EN language parity: PASS — 7096/7096 keys.
- Duplicate language keys: PASS — 0 RU / 0 EN.
- XML parse: PASS.
- Known Moodle 4.1 regression `html_writer::strong()`: absent.
- User-facing language strings containing the technical word `Moodle`: 0 outside privacy metadata.
- Protected DB/ACL/privacy files: byte-identical to alpha20 (`db/install.xml`, `db/upgrade.php`, `db/upgradelib.php`, `db/access.php`, `classes/privacy/provider.php`).

## Beta acceptance status
NOT RUN in this environment:
- live Moodle 4.1.22 install/upgrade;
- PHP 7.3 runtime;
- production DB;
- real KBA Theme desktop/mobile;
- real cron / message processors;
- real Google OAuth / Google Calendar API;
- concurrent resource-booking race on production database;
- PHPUnit/Behat full suite;
- production/real-customer acceptance.

Beta1 is an acceptance baseline, not a production-readiness declaration.
