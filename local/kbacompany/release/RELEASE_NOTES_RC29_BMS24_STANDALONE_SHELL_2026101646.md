# BMS24 RC29 build2026101646 — Standalone owned App Shell

## Что изменено
- Owned mode теперь использует Moodle `embedded` page layout вместо `standard`.
- Moodle/KBA navigation/header/footer chrome больше не должен окружать BMS24 owned shell.
- Moodle остаётся runtime/security infrastructure: login, session, capabilities, sesskey, routing, renderer и asset APIs не обходятся.
- В owned mode добавляется plugin-owned body class `bms24-owned-page`.
- BMS24 shell занимает высоту viewport (`min-height: 100vh`) и больше не оформляется как вложенная карточка с внешней рамкой/скруглением.
- Legacy mode не изменён.

## Область изменений
- `classes/local/bms24_shell_service.php`
- `bms_ui/owned_shell.css`
- `tests/bms24_shell_service_test.php`
- `version.php`
- `db/upgrade.php`

## DB / ACL / Privacy / Company isolation
- DB schema: без изменений.
- ACL/RBAC: без изменений.
- Privacy: без изменений.
- Company isolation: без изменений.

## Rollback
В админке Moodle переключить `local_kbacompany/bms24_shell_mode` обратно в `legacy` и очистить кеши. Данные откатывать не требуется.
