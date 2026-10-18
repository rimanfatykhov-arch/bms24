# BMS24 — current release snapshot: RC29 / build2026101642

**Validated:** ZIP source archive CRC and `version.php` as of 18.10.2026. **Not verified:** live Moodle install and company security matrix.

## Sequence since 1633

- **1633** Position Coverage requests, approval, audit and dates.
- **1634** Explicit position ownership of ordinary tasks, calculated effective executor and separate original `assigneeid`.
- **1635** Responsibility selector/create-edit UX hotfix.
- **1636** Recurring series `dutypositionuuid`, independent series/date instance idempotency.
- **1637** Read-only approved coverage in Battle Plan.
- **1638** Company/permission gated position duty schedule, metrics and calendar links.
- **1639** Rollback and inactive position hardening; regression harness.
- **1640** Plugin-owned App Shell opt-in (legacy default), own templates and BMOS palette.
- **1641** UI Kit components, styleguide and scoped component CSS.
- **1642** Task Center presentation W3 first slice.

## 1642 changes (verified against local release notes)

New: `bms_ui/task_center.css`; `templates/bms24_task_{header,panel,tabs,toolbar}.mustache`; `tests/bms24_task_owned_presentation_test.php`.

Changed: `tasks.php`; `classes/local/{bms24_shell_service,bms24_ui_kit_service,task_ui_service}.php`; `templates/bms24_ui_field.mustache`; `db/upgrade.php` (no-DDL savepoint); `version.php`.

### Behavior

- Opt-in owned shell turns on typed Task Center header/tabs/toolbar/panels/filters; legacy mode retains existing UI.
- Supports `uuid,scope,display,q,status,stream,sourcekind` query filters; `q` max length 200. Keeps old `name`/`id` HTML attributes and POST task actions, sesskey, expected revision, AJAX duties lookup.
- No domain model, company ACL, recurring scheduler, approved Battle Plan, or schema changes.
- Legacy CSS and `bms-tui`, `bms-task2-*` DOM hooks intentionally remain for compatibility. This is **partial**, not full decoupling.

## Reported local QA for 1642 (from its release notes; not re-run in this pack)

PHP lint 397/397, RU/EN parity 8085 keys, XMLDB valid, 16 isolated PHP presentation tests, scoped CSS and mock Chromium 1440/390, ZIP CRC/SHA. Added Moodle PHPUnit test was NOT run on Moodle DB.

## Mandatory staging scenarios

- KBA Theme and Boost: `legacy` and `owned` compare tasks list/detail/create/edit/kanban/overview.
- All `create/edit/classifyduty/start/submit/checkset/attachmentadd/discussionpost` with manager vs ordinary employee; company cross-boundary direct URL+POST.
- Search/form preserve `id`, `name`, query params and old JS links. Styles scoped; no duplicate headers; keyboard focus; 390 px table scroll.
- Approval/private data stays role protected. Archive only after backup and restore plan.

## Next planned work

W3.2: recurring series UI Kit migration; W3.3: Battle Plan; W3.4: People; W3.5: Management; W4: theme-independent shell hardening. Before FINAL, real Moodle PHPUnit, MySQL upgrade, cron concurrency, company security and restore tests remain hard gates.
