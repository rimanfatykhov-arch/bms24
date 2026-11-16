# Сводка дня #107 поверх опубликованного #110

Продуктовая задача: [#106](https://github.com/rimanfatykhov-arch/bms24/issues/106).
Production: #110 `43c528d23836d14c08e14be5b3441916b763808f`.
Источник сводки: #107 `8e503f5126ed314f56c0ef971c407dcd7073db17`.
Новых бизнес-правил, таблиц, прав или миграций интеграция не вводит.

| Пересечение | Решение | Проверка |
|---|---|---|
| task_service.php | Сохранены history50+opened20 и исключение opened из latest activity; добавлен own-query adapter сводки | task_open_history_test + daily_report_service_test |
| tasks.php | Сохранён task_open JS/marker после рендера; ссылка на сводку только в списке | existing56 BROWSER + targeted dev summary smoke |
| RU/EN | Побайтовый опубликованный110 prefix, затем 18 прежних dailyreport keys | prefix hash, no override, parity, mutation rejection |
| preservation guard | Точный merged full hash, без blanket exemptions | старые и новые отрицательные тесты |
| historical source evidence | Исходные #102/#110 JSON не переписаны; новая before→after запись | последовательная цепочка before==previous after |
| BROWSER/rehearsal | Проверки #110 перенесены полностью, required names56 не сокращены | contract31, merge gate, actual dual-engine workflows |

Сводка читает только `action=submit`, поэтому открытия не считаются выполненной
работой и не попадают в дневные результаты/время. Ссылка в сводке открывает
каноническую задачу; опубликованная фиксация открытия продолжает работать.
Результат задачи остаётся историческим payload submit, а не перезаписываемым
текущим result. Текущее назначение/ACL ограничивают старые события до SQL fetch.

Состав кандидата: опубликованные #103/#110 + личная read-only сводка #107.
Нет draft/submission report, manager queue, новой интеграции или авторизации.
Это FULL по действующей политике, не ускоренный CSS-выпуск.
Production/staging при подготовке не меняются; текущий preview не заменяется
во время просмотра владельцем. Перед публикацией проверяется актуальный #110
baseline, состав и новый пакет, затем требуется отдельное «публикуй».
