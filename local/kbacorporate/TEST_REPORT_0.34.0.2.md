# Test Report v0.34.0.2

## Проверенная проблема

Исходный v0.34.0.1 вызывал `assign_capability()` для `managecompanyimplementation` до того, как Moodle зарегистрировал новое capability из `db/access.php`.

## Статические проверки

- `db/access.php` содержит `local/kbacorporate:managecompanyimplementation`.
- В upgrade step `2026090902` отсутствует преждевременный `assign_capability()`.
- Step `2026090903` вызывает `update_capabilities('local_kbacorporate')` до назначения прав.
- `assign_capability()` защищён проверкой наличия записи в `{capabilities}`.
- Версия плагина и savepoint согласованы: `2026090903`.
- Повторное назначение идемпотентно благодаря `overwrite=true`.

## Release gate

- PHP syntax: проверяется для всех PHP-файлов пакета.
- XML: `db/install.xml` проверяется на корректность.
- ZIP: проверяются единый корень `kbacorporate/` и integrity.
- Schema impact: отсутствует.
- Destructive migration: отсутствует.

## Runtime-сценарий на пилотном Moodle

1. Заменить v0.34.0.1 на полный ZIP v0.34.0.2.
2. Повторно открыть `/admin/index.php` и продолжить обновление.
3. Убедиться, что `codingerror` не возникает.
4. Очистить кэши и повторно войти корпоративным менеджером.
5. Проверить карточку «Внедрение BMS» только в назначенной компании.
6. Проверить запрет доступа к чужой компании и глобальному Framework Manager.

Runtime-проверка выполняется на сервере пользователя; локальная сборка не содержит полной Moodle DB/runtime среды.
