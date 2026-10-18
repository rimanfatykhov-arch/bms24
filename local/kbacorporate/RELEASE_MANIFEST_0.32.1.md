# Release Manifest — v0.32.1

- Component: `local_kbacorporate`
- Release: `0.32.1 — История оценок компетенций сотрудника`
- Build: `2026090806`
- Minimum Moodle: `4.0` (`2022041900`)
- Maturity: `BETA`
- Upgrade source: v0.32.0
- Classification: MINOR, backward-compatible

## New files

- `employee_assessment_history.php`
- `employee_assessment_history_export.php`
- `classes/local/employee_assessment_history.php`
- `tests/employee_assessment_history_test.php`
- `RELEASE_NOTES_0.32.1.md`
- `CHANGELOG_0.32.1.md`
- `TEST_REPORT_0.32.1.md`
- `AI_DECISION_LOG_0.32.1.md`
- `RELEASE_MANIFEST_0.32.1.md`

## Modified files

- `classes/local/employee_competency_profile.php`
- `employee_competency_profile.php`
- `employee_competency_history.php`
- `employee_gap.php`
- `employee.php`
- `lang/ru/local_kbacorporate.php`
- `lang/en/local_kbacorporate.php`
- `styles.css`
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

None. `db/install.xml` is unchanged. `db/upgrade.php` records savepoint `2026090806` only.

## Entry points

- `/local/kbacorporate/employee_assessment_history.php?companyid=<id>&userid=<id>`
- `/local/kbacorporate/employee_assessment_history_export.php?companyid=<id>&userid=<id>`

## Safety contract

- Read-only projection.
- Company and employee visibility checks are preserved.
- CSV requires export capability.
- Pending/rejected/missing events do not change the calculated level trend.
- No automatic grading, enrolment or plan changes.
