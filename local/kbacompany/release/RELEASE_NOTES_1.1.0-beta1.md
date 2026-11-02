# local_kbacompany 1.1.0-beta1 build2026101213

## Feature Freeze & Acceptance Baseline

This release freezes the BMS24 Calendar 1.1 feature set after alpha20 and establishes the beta acceptance baseline.

### Included frozen capabilities
- unified BMS24 Calendar over learning events, BMS tasks, meetings, Battle Plans, management checkpoints and management cycles;
- Month / Week / Day / Analytics views;
- manual learning-event CRUD and safe drag/drop rescheduling;
- day planner, overlap diagnostics and workload signals;
- team workload and meeting-time finder;
- Today workspace and idempotent notification engine with user/company preferences and do-not-disturb;
- recurring manual events and regular BMS meetings;
- regular-management and planning-meeting workflow projections;
- meeting resources, online links, capacity checks and double-booking protection;
- Google Calendar provider projection and explicit BMS Meeting -> Google sync;
- read-only calendar analytics.

### Beta1 stabilization changes
- plugin maturity changed from ALPHA to BETA;
- Calendar contract version set to 1.1.0-beta1;
- no new feature, route, data model or lifecycle is introduced;
- remaining user-facing Google OAuth wording no longer exposes the technical platform name; technical/privacy references remain unchanged;
- added the Beta acceptance checklist and regression inventory.

### Schema
No schema change relative to 1.1.0-alpha20. `db/install.xml`, `db/upgrade.php`, `db/upgradelib.php`, `db/access.php` and the privacy provider remain byte-identical.

### Live acceptance status
NOT RUN in this build environment:
- Moodle 4.1.22 upgrade/install;
- PHP 7.3 runtime;
- production MySQL/MariaDB;
- real KBA Theme desktop/mobile visual acceptance;
- real cron, popup/email delivery and timezone/DST cases;
- live Google OAuth/API create/update/readback;
- concurrent meeting-resource booking on the production database;
- real multi-company and multi-role acceptance.
