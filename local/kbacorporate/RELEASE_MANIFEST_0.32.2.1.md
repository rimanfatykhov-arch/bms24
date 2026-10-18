# Release Manifest — v0.32.2.1

- Component: `local_kbacorporate`
- Release: `0.32.2.1 — Исправление ссылки на шаблон учебного плана Moodle`
- Build: `2026090808`
- Minimum Moodle: `4.0` (`2022041900`)
- Maturity: `BETA`
- Upgrade source: `v0.32.2`
- Classification: `PATCH`, backward-compatible

## Modified files

- `classes/local/moodle_learning_plans.php`
- `version.php`
- `db/upgrade.php`
- `MASTER_INDEX.md`
- `CURRENT_STATE.md`
- `ROADMAP.md`
- `README.md`
- `CHANGELOG.md`
- `RELEASE_NOTES.md`
- `RELEASE_MANIFEST.md`

## New files

- `tests/moodle_learning_plan_urls_test.php`
- `RELEASE_NOTES_0.32.2.1.md`
- `CHANGELOG_0.32.2.1.md`
- `TEST_REPORT_0.32.2.1.md`
- `AI_DECISION_LOG_0.32.2.1.md`
- `RELEASE_MANIFEST_0.32.2.1.md`
- `06_AI_Knowledge_Base/Troubleshooting/moodle-template-pagecontextid.md`
- `06_AI_Knowledge_Base/Development Log/v0.32.2.1.md`

## Database changes

None. `db/install.xml` remains unchanged. `db/upgrade.php` records savepoint `2026090808` only.

## Fixed URL contract

```text
/admin/tool/lp/templatecompetencies.php?templateid=<id>&pagecontextid=<contextid>
```
