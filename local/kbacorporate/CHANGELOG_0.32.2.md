# Changelog — v0.32.2

## Added

- `classes/local/competency_reassessment.php` — projection, summary, outcomes and evidence refresh.
- `competency_reassessment_start.php` — explicit pre-creation review.
- `competency_reassessment.php` — before/after confirmation workflow.
- `tests/competency_reassessment_test.php` — outcome, summary, cutoff and baseline guards.

## Changed

- completed development plans now open the reassessment preparation/workspace;
- development-audit creation snapshots baseline, target, course and evidence cutoff;
- old Moodle grades are not auto-confirmed as post-development results;
- new evidence produces pending proposals only;
- rejected assessment items may be resubmitted;
- development audits in lists open the dedicated reassessment workspace;
- RU/EN language packs updated;
- plugin version raised to `2026090807`.

## Database

No schema changes. Upgrade savepoint only.
