# Release Manifest — v0.32.2

- Component: `local_kbacorporate`
- Release: `0.32.2 — Повторная оценка компетенций`
- Build: `2026090807`
- Minimum Moodle: `4.0` (`2022041900`)
- Maturity: `BETA`
- Upgrade source: v0.32.1
- Classification: MINOR, backward-compatible

## New files

- `classes/local/competency_reassessment.php`
- `competency_reassessment_start.php`
- `competency_reassessment.php`
- `tests/competency_reassessment_test.php`
- `RELEASE_NOTES_0.32.2.md`
- `CHANGELOG_0.32.2.md`
- `TEST_REPORT_0.32.2.md`
- `AI_DECISION_LOG_0.32.2.md`
- `RELEASE_MANIFEST_0.32.2.md`

## Modified files

- `classes/local/competency_audits.php`
- `development_plan.php`
- `study_plan_progress.php`
- `competency_audits.php`
- `competency_audit.php`
- `lang/ru/local_kbacorporate.php`
- `lang/en/local_kbacorporate.php`
- `version.php`
- `db/upgrade.php`
- `CURRENT_STATE.md`
- `MASTER_INDEX.md`
- `ROADMAP.md`
- `README.md`
- `CHANGELOG.md`
- `RELEASE_NOTES.md`
- `RELEASE_MANIFEST.md`

## Database changes

None. `db/install.xml` remains unchanged. `db/upgrade.php` records savepoint `2026090807` only.

## Entry points

- `/local/kbacorporate/competency_reassessment_start.php?planid=<id>`
- `/local/kbacorporate/competency_reassessment.php?id=<auditid>`

## Safety contract

- old grades are not auto-confirmed as reassessment results;
- new evidence creates pending proposals only;
- confirmation requires Moodle competency-grade capability;
- no automatic course enrolment or grading from completion;
- no automatic rewrite of legacy audits;
- repeated creation reuses the existing follow-up audit.
