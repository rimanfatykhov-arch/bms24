# BMS 0.6.9.9.1 — Русская терминология бизнес-модели

Build: `2026092201`

## Решение
Russian BMS UI uses Russian business terms as the primary vocabulary. English abbreviations remain only as internal storage/integration identifiers where compatibility requires them.

Каноническая пользовательская цепочка:

**Требование собственника → Как есть → Факт / Требуются данные / Гипотеза / Открытый вопрос → Как должно быть → Проверка целевой модели → Разрыв → Способ изменения → Показатель результата.**

Способы изменения показываются по-русски:
- Действие
- Решение
- Проверка гипотезы
- Проект
- Программа
- Регулярная работа

## Изменения интерфейса
- `REQ` → «Требование собственника»
- `AS-IS` → «Как есть»
- `FACT` → «Факт»
- `DATA` → «Требуются данные»
- `HYP` → «Гипотеза»
- `OPEN` → «Открытый вопрос»
- `TARGET` → «Как должно быть» / «Целевое состояние»
- `GAP` → «Разрыв»
- `change mechanism` → «Способ изменения»
- `KPI` → «Показатель результата»

The database enum values and technical relation codes are not renamed in this patch, so existing records and integrations remain compatible.

## Границы изменения
This is a schema-neutral terminology patch on top of 0.6.9.9. Business Model logic, snapshots, UUIDs, Stable Codes, Owner Requirement pinning, permissions and Company isolation are unchanged.
