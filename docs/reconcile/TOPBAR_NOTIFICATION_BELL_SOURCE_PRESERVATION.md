# Колокольчик в верхнем меню: сохранность закреплённых файлов

**Назначение:** запись о проверенном изменении для `tools/check_pr53_pr56_integration.py`.
Не является разрешением на выпуск и не заменяет приёмку в работе.

## Изменённые закреплённые файлы

| Файл | Изменение |
|---|---|
| `local/kbacompany/classes/local/communication_service.php` | Добавлены `notification_source_visible()` (public: им же пользуется `notification_bell_service::claim_signal`), `visible_unread_notification_count()` и `notification_feed()` (только чтение). `context()` считает непрочитанные через общую функцию; правило видимости то же: компания → активный участник → текущий доступ к объекту. |
| `local/kbacompany/lang/en/local_kbacompany.php` | 18 строк: `notificationbell_*`, `privacy:metadata:preference:notificationsound` и `privacy:metadata:preference:notificationsoundsignalled` добавлены в самый конец, после блока #145 |
| `local/kbacompany/lang/ru/local_kbacompany.php` | те же 18 ключей, в конец |

Цепочка хэшей (LF) записана в `docs/reconcile/topbar-notification-bell-source-preservation.json`:
`before` каждого файла совпадает с последним закреплённым `after` (E2 для `communication_service.php`, #145 для языков).

## Что не менялось

- Схема, `install.xml`, `upgrade.php`, версия `2026111601`.
- Генерация уведомлений, cron, правила прочтения, mark_all.
- Права и capability.

## Проверка

- `python tools/check_pr53_pr56_integration.py` → PASS
- `python -m unittest tools.tests.test_dailyreport_source_preservation tools.tests.test_pr102_source_preservation` → 8 OK
- `python tools/check_reconcile_1879.py` → PASS
