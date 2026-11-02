# RC29 build2026101472 — Avatar Moodle API Hotfix

## Причина
В build2026101470–1471 вызван несуществующий метод `user_picture::output()` (на карточке сотрудника и в self-profile). Ошибка воспроизведена пользовательским скриншотом: `employee_ui_service.php:262`.

## Исправления
- `employee_ui_service::detail()` — `$GLOBALS['OUTPUT']->user_picture($avataruser, ['size' => 96])`.
- `employee_self_profile.php` — `$OUTPUT->user_picture($profile, ['size' => 100])`.
- Тот же Moodle core picture pipeline, без новых файлов/таблиц; ACL не изменён.
- build 2026101472, one no-op upgrade savepoint.

## Приёмка
1. Обновить плагин до build2026101472, очистить кеши Moodle и открыть карточку любого сотрудника.
2. Фото отображается или показывается стандартная заглушка; исключений больше нет.
3. Открыть «Редактировать профиль» сотрудника и проверить фото и сохранение личных полей.
4. Изменить фото через штатный Moodle editor и проверить отображение в карточке BMS24.
5. Проверить доступ сотрудника другой компании.

Live Moodle / PHPUnit: NOT RUN. FINAL NOT READY.
