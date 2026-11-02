# RC29 build2026101515 — исправление ссылки обсуждения задачи

Ошибка Moodle: `Url parameters values can not be arrays!` при открытии задачи, stack task_ui_service.php:258.

Причина: в `objectuuid` передавался массив `$task`, вместо строкового `$task['uuid']`.

Изменено в `classes/local/task_ui_service.php`: для ссылки на `communication_center.php` передаётся строковый UUID; остальные параметры (`uuid`, `tab`, `objectkind`) сохраняются. Изменения данных и БД отсутствуют.

Установите `local_kbacompany` build2026101515, выполните обновление Moodle и очистите кеш. Проверьте вкладку «Обсуждение» задачи без thread_uuid и ссылку в коммуникационный центр.

Статическая проверка PHP и целостности архива не заменяет тест в Moodle.
