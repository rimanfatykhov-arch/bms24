# BMS 0.5.10 — Управленческий обзор Боевых планов

## Read-model

`battle_plan_management_overview_service` не хранит агрегаты. Источники:

- `local_kbacompany_bplan` / `local_kbacompany_bplanitem`;
- `local_kbacompany_position` / `local_kbacompany_orgnode`;
- canonical position indicator bindings;
- `indicator_management_exception_service`;
- canonical `local_kbacompany_task`.

## Оперативная выборка

В обзор входят доступные пользователю Боевые планы выбранной недели со статусом, отличным от `closed`. Подразделение определяется через должность плана (`position.orgnodeuuid`). Руководитель/проверяющий — `bplan.reviewerid`.

## Сигнал внимания

Приоритет строки определяется только явными фактами:

1. требуется новое управленческое решение;
2. есть просроченная canonical Task;
3. есть критическое исключение;
4. есть открытая работа/исключение;
5. нет текущих исключений.

Процентные или возрастные пороги не вводятся.

## ACL

Задача попадает в агрегаты только если `task_service::can_view_task()` разрешает пользователю её видеть. Обзор не является альтернативным способом прочитать скрытый Task.
